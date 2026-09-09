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
 * emit (hundredth+) (#36387 / #36386).
 *
 * Exponents 96–99 live in {@see JitPowIntegerEmitExponents96to99}; 92–95 in
 * {@see JitPowIntegerEmitExponents92to95}; 86–88 in
 * {@see JitPowIntegerEmitExponents86to88}; 89–91 in
 * {@see JitPowIntegerEmitExponents80to91}; 80–85 in
 * {@see JitPowIntegerEmitExponents80to85}.
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
            'hundredth' => true,
            'hundredfirst' => true,
        ];
        if (!isset($high[$expFold])) {
            return false;
        }

        if ('hundredth' === $expFold) {
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_hundredth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_hundredth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_hundredth_done');
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
            $hundredthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $hundredthFEighth = $context->builder->fmul($hundredthFSq, $sqF);
            $hundredthF = $context->builder->fmul($hundredthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
                $hundredthF = $context->builder->fmul($hundredthF, $nF);
                    $hundredthF = $context->builder->fmul($hundredthF, $nF);
                        $hundredthF = $context->builder->fmul($hundredthF, $nF);
                        $hundredthF = $context->builder->fmul($hundredthF, $nF);
                        $hundredthF = $context->builder->fmul($hundredthF, $nF);
                        $hundredthF = $context->builder->fmul($hundredthF, $nF);
                        $hundredthF = $context->builder->fmul($hundredthF, $nF);
                        $hundredthF = $context->builder->fmul($hundredthF, $nF);
                        $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);

            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $hundredthF = $context->builder->fmul($hundredthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_hundredth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_hundredth_cu_ok');
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
            $hundredthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $hundredthF2Eighth = $context->builder->fmul($hundredthF2Sq, $sqF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
                $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
                    $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
                        $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
                        $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
                        $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
                        $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
                        $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
                        $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
                        $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);

            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $hundredthF2 = $context->builder->fmul($hundredthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF2
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_hundredth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_hundredth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $hundredthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $hundredthF3Eighth = $context->builder->fmul($hundredthF3Sq, $sqF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
                $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
                    $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
                        $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
                        $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
                        $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
                        $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
                        $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
                        $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
                        $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);

            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $hundredthF3 = $context->builder->fmul($hundredthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF3
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_hundredth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_hundredth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $hundredthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $hundredthF4Eighth = $context->builder->fmul($hundredthF4Sq, $sqF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
                $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
                    $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
                        $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
                        $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
                        $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
                        $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
                        $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
                        $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
                        $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);

            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $hundredthF4 = $context->builder->fmul($hundredthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF4
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_hundredth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_hundredth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $hundredthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $hundredthF5Eighth = $context->builder->fmul($hundredthF5Sq, $sqF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
                $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
                    $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
                        $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
                        $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
                        $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
                        $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
                        $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
                        $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
                        $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);

            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $hundredthF5 = $context->builder->fmul($hundredthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF5
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_hundredth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_hundredth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $hundredthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $hundredthF6Eighth = $context->builder->fmul($hundredthF6Sq, $sqF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
                $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
                    $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
                        $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
                        $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
                        $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
                        $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
                        $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
                        $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
                        $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);

            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $hundredthF6 = $context->builder->fmul($hundredthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF6
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_hundredth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_hundredth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $hundredthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $hundredthF7Eighth = $context->builder->fmul($hundredthF7Sq, $sqF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
                $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
                    $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
                        $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
                        $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
                        $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
                        $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
                        $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
                        $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
                        $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);

            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $hundredthF7 = $context->builder->fmul($hundredthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF7
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_hundredth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_hundredth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $hundredthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $hundredthF8Eighth = $context->builder->fmul($hundredthF8Sq, $sqF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
                $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
                    $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
                        $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
                        $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
                        $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
                        $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
                        $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
                        $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
                        $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);

            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $hundredthF8 = $context->builder->fmul($hundredthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF8
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_hundredth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_hundredth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $hundredthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
                $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
                    $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
                        $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
                        $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
                        $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
                        $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
                        $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
                        $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
                        $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);

            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $hundredthF9 = $context->builder->fmul($hundredthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF9
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_hundredth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_hundredth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $hundredthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
                $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
                    $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
                        $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
                        $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
                        $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
                        $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
                        $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
                        $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
                        $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);

            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $hundredthF10 = $context->builder->fmul($hundredthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF10
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $hundredthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
                $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
                    $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
                        $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
                        $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
                        $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
                        $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
                        $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
                        $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
                        $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);

            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $hundredthF11 = $context->builder->fmul($hundredthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF11
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $hundredthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
                $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
                    $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
                        $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
                        $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
                        $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
                        $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
                        $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
                        $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
                        $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);

            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $hundredthF12 = $context->builder->fmul($hundredthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF12
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $hundredthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
                $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
                    $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
                        $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
                        $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
                        $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
                        $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
                        $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
                        $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
                        $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);

            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $hundredthF13 = $context->builder->fmul($hundredthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF13
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $hundredthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
                $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
                    $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
                        $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
                        $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
                        $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
                        $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
                        $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
                        $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
                        $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);

            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $hundredthF14 = $context->builder->fmul($hundredthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF14
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $hundredthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
                $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
                    $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
                        $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
                        $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
                        $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
                        $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
                        $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
                        $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
                        $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);

            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $hundredthF15 = $context->builder->fmul($hundredthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF15
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $hundredthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
                $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
                    $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
                        $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
                        $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
                        $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
                        $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
                        $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
                        $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
                        $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);

            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $hundredthF16 = $context->builder->fmul($hundredthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF16
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $hundredthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
                $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
                    $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
                        $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
                        $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
                        $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
                        $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
                        $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
                        $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
                        $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);

            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $hundredthF17 = $context->builder->fmul($hundredthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF17
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $hundredthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
                $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
                    $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
                        $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
                        $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
                        $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
                        $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
                        $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
                        $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
                        $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);

            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $hundredthF18 = $context->builder->fmul($hundredthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF18
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $hundredthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
                $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
                    $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
                        $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
                        $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
                        $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
                        $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
                        $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
                        $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
                        $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);

            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $hundredthF19 = $context->builder->fmul($hundredthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF19
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_hundredth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $hundredthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
                    $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
                        $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
                        $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
                        $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
                        $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
                        $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
                        $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
                        $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);

            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $hundredthF20 = $context->builder->fmul($hundredthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF20
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $hundredthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
                    $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
                    $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
                    $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
                    $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
                    $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
                    $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
                    $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);

            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $hundredthF21 = $context->builder->fmul($hundredthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF21
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $hundredthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
                $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
                $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
                $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
                $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
                $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
                $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);

            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $hundredthF22 = $context->builder->fmul($hundredthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF22
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $hundredthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);

            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $hundredthF23 = $context->builder->fmul($hundredthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF23
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $hundredthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);

            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $hundredthF24 = $context->builder->fmul($hundredthF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF24
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $hundredthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);

            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $hundredthF25 = $context->builder->fmul($hundredthF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF25
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $hundredthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);

            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $hundredthF26 = $context->builder->fmul($hundredthF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF26
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $hundredthF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);

            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $hundredthF27 = $context->builder->fmul($hundredthF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF27
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $hundredthF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);

            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $hundredthF28 = $context->builder->fmul($hundredthF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF28
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $hundredthF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);

            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $hundredthF29 = $context->builder->fmul($hundredthF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF29
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_hundredth_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $hundredthF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $hundredthF30 = $context->builder->fmul($hundredthF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF30
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_hundredth_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_hundredth_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $hundredthF31 = $context->builder->fmul($seventiethF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $hundredthF31 = $context->builder->fmul($hundredthF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF31
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $hundredthF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $hundredthF32 = $context->builder->fmul($hundredthF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF32
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_hundredth_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_hundredth_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $hundredthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $hundredthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $hundredthF33 = $context->builder->fmul($hundredthF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF33
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_hundredth_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_hundredth_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $hundredthF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $hundredthF34 = $context->builder->fmul($hundredthF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF34
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $hundredthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $hundredthF35 = $context->builder->fmul($hundredthF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF35
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $hundredthF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $hundredthF36 = $context->builder->fmul($hundredthF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF36
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_hundredth_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_hundredth_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $hundredthF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $hundredthF37 = $context->builder->fmul($hundredthF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF37
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $hundredthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $hundredthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $hundredthF38 = $context->builder->fmul($hundredthF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF38
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $hundredthF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $hundredthF39 = $context->builder->fmul($hundredthF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF39
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_hundredth_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $hundredthF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $hundredthF40 = $context->builder->fmul($hundredthF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF40
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_hundredth_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_hundredth_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $hundredthF41 = $context->builder->fmul($eightiethF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $hundredthF41 = $context->builder->fmul($hundredthF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF41
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $hundredthF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $hundredthF42 = $context->builder->fmul($hundredthF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF42
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_hundredth_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_hundredth_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $hundredthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredthF43 = $context->builder->fmul($hundredthF43, $nF43);
            $hundredthF43 = $context->builder->fmul($hundredthF43, $nF43);
            $hundredthF43 = $context->builder->fmul($hundredthF43, $nF43);
            $hundredthF43 = $context->builder->fmul($hundredthF43, $nF43);
            $hundredthF43 = $context->builder->fmul($hundredthF43, $nF43);
            $hundredthF43 = $context->builder->fmul($hundredthF43, $nF43);
            $hundredthF43 = $context->builder->fmul($hundredthF43, $nF43);
            $hundredthF43 = $context->builder->fmul($hundredthF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF43
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_hundredth_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_hundredth_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $hundredthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredthF44 = $context->builder->fmul($hundredthF44, $nF44);
            $hundredthF44 = $context->builder->fmul($hundredthF44, $nF44);
            $hundredthF44 = $context->builder->fmul($hundredthF44, $nF44);
            $hundredthF44 = $context->builder->fmul($hundredthF44, $nF44);
            $hundredthF44 = $context->builder->fmul($hundredthF44, $nF44);
            $hundredthF44 = $context->builder->fmul($hundredthF44, $nF44);
            $hundredthF44 = $context->builder->fmul($hundredthF44, $nF44);
            $hundredthF44 = $context->builder->fmul($hundredthF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF44
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $hundredthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($hundredthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($hundredthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($hundredthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($hundredthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($hundredthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($hundredthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($hundredthF45, $nF45);
            $hundredthF45 = $context->builder->fmul($hundredthF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF45
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $hundredthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $hundredthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $hundredthF46 = $context->builder->fmul($hundredthF46, $nF46);
            $hundredthF46 = $context->builder->fmul($hundredthF46, $nF46);
            $hundredthF46 = $context->builder->fmul($hundredthF46, $nF46);
            $hundredthF46 = $context->builder->fmul($hundredthF46, $nF46);
            $hundredthF46 = $context->builder->fmul($hundredthF46, $nF46);
            $hundredthF46 = $context->builder->fmul($hundredthF46, $nF46);
            $hundredthF46 = $context->builder->fmul($hundredthF46, $nF46);
            $hundredthF46 = $context->builder->fmul($hundredthF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF46
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_hundredth_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_hundredth_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $hundredthF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $hundredthF47 = $context->builder->fmul($hundredthF47, $nF47);
            $hundredthF47 = $context->builder->fmul($hundredthF47, $nF47);
            $hundredthF47 = $context->builder->fmul($hundredthF47, $nF47);
            $hundredthF47 = $context->builder->fmul($hundredthF47, $nF47);
            $hundredthF47 = $context->builder->fmul($hundredthF47, $nF47);
            $hundredthF47 = $context->builder->fmul($hundredthF47, $nF47);
            $hundredthF47 = $context->builder->fmul($hundredthF47, $nF47);
            $hundredthF47 = $context->builder->fmul($hundredthF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF47
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $hundredthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $hundredthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $hundredthF48 = $context->builder->fmul($hundredthF48, $nF48);
            $hundredthF48 = $context->builder->fmul($hundredthF48, $nF48);
            $hundredthF48 = $context->builder->fmul($hundredthF48, $nF48);
            $hundredthF48 = $context->builder->fmul($hundredthF48, $nF48);
            $hundredthF48 = $context->builder->fmul($hundredthF48, $nF48);
            $hundredthF48 = $context->builder->fmul($hundredthF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF48
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $hundredthF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $hundredthF49 = $context->builder->fmul($hundredthF49, $nF49);
            $hundredthF49 = $context->builder->fmul($hundredthF49, $nF49);
            $hundredthF49 = $context->builder->fmul($hundredthF49, $nF49);
            $hundredthF49 = $context->builder->fmul($hundredthF49, $nF49);
            $hundredthF49 = $context->builder->fmul($hundredthF49, $nF49);
            $hundredthF49 = $context->builder->fmul($hundredthF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF49
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (eightyninth)');
            }
            $eightyninthLong = JITVariable::KIND_VARIABLE === $eightyninthVar->kind
                ? $context->builder->load($eightyninthVar->value)
                : $eightyninthVar->value;
            $ov50Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyninth_ov');
            $ok50Block = BasicBlockHelper::append($context, 'pow_hundredth_eightyninth_ok');
            $context->builder->branchIf($ov50, $ov50Block, $ok50Block);

            $context->builder->positionAtEnd($ov50Block);
            $eightyninthF50 = $context->builder->load($eightyninthVar->longArithOverflowDoubleSlot);
            $nF50 = $context->builder->siToFp($n, $f64);
            $hundredthF50 = $context->builder->fmul($eightyninthF50, $nF50);
            $hundredthF50 = $context->builder->fmul($hundredthF50, $nF50);
            $hundredthF50 = $context->builder->fmul($hundredthF50, $nF50);
            $hundredthF50 = $context->builder->fmul($hundredthF50, $nF50);
            $hundredthF50 = $context->builder->fmul($hundredthF50, $nF50);
            $hundredthF50 = $context->builder->fmul($hundredthF50, $nF50);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF50
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetieth)');
            }
            $ninetiethLong = JITVariable::KIND_VARIABLE === $ninetiethVar->kind
                ? $context->builder->load($ninetiethVar->value)
                : $ninetiethVar->value;
            $ov51Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetieth_ov');
            $ok51Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetieth_ok');
            $context->builder->branchIf($ov51, $ov51Block, $ok51Block);

            $context->builder->positionAtEnd($ov51Block);
            $ninetiethF51 = $context->builder->load($ninetiethVar->longArithOverflowDoubleSlot);
            $nF51 = $context->builder->siToFp($n, $f64);
            $hundredthF51 = $context->builder->fmul($ninetiethF51, $nF51);
            $hundredthF51 = $context->builder->fmul($hundredthF51, $nF51);
            $hundredthF51 = $context->builder->fmul($hundredthF51, $nF51);
            $hundredthF51 = $context->builder->fmul($hundredthF51, $nF51);
            $hundredthF51 = $context->builder->fmul($hundredthF51, $nF51);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF51
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetyfirst)');
            }
            $ninetyfirstLong = JITVariable::KIND_VARIABLE === $ninetyfirstVar->kind
                ? $context->builder->load($ninetyfirstVar->value)
                : $ninetyfirstVar->value;
            $ov52Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyfirst_ov');
            $ok52Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyfirst_ok');
            $context->builder->branchIf($ov52, $ov52Block, $ok52Block);

            $context->builder->positionAtEnd($ov52Block);
            $ninetyfirstF52 = $context->builder->load($ninetyfirstVar->longArithOverflowDoubleSlot);
            $nF52 = $context->builder->siToFp($n, $f64);
            $hundredthF52 = $context->builder->fmul($ninetyfirstF52, $nF52);
            $hundredthF52 = $context->builder->fmul($hundredthF52, $nF52);
            $hundredthF52 = $context->builder->fmul($hundredthF52, $nF52);
            $hundredthF52 = $context->builder->fmul($hundredthF52, $nF52);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF52
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetysecond)');
            }
            $ninetysecondLong = JITVariable::KIND_VARIABLE === $ninetysecondVar->kind
                ? $context->builder->load($ninetysecondVar->value)
                : $ninetysecondVar->value;
            $ov53Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetysecond_ov');
            $ok53Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetysecond_ok');
            $context->builder->branchIf($ov53, $ov53Block, $ok53Block);

            $context->builder->positionAtEnd($ov53Block);
            $ninetysecondF53 = $context->builder->load($ninetysecondVar->longArithOverflowDoubleSlot);
            $nF53 = $context->builder->siToFp($n, $f64);
            $hundredthF53 = $context->builder->fmul($ninetysecondF53, $nF53);
            $hundredthF53 = $context->builder->fmul($hundredthF53, $nF53);
            $hundredthF53 = $context->builder->fmul($hundredthF53, $nF53);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF53
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
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetythird)');
            }
            $ninetythirdLong = JITVariable::KIND_VARIABLE === $ninetythirdVar->kind
                ? $context->builder->load($ninetythirdVar->value)
                : $ninetythirdVar->value;
            $ov54Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetythird_ov');
            $ok54Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetythird_ok');
            $context->builder->branchIf($ov54, $ov54Block, $ok54Block);

            $context->builder->positionAtEnd($ov54Block);
            $ninetythirdF54 = $context->builder->load($ninetythirdVar->longArithOverflowDoubleSlot);
            $nF54 = $context->builder->siToFp($n, $f64);
            $hundredthF54 = $context->builder->fmul($ninetythirdF54, $nF54);
            $hundredthF54 = $context->builder->fmul($hundredthF54, $nF54);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF54
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok54Block);
            $ninetyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetythirdLong,
                $n
            );
            $ov55 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyfourthVar->longArithOverflowFlag);
            if (null === $ov55 || null === $ninetyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetyfourth)');
            }
            $ninetyfourthLong = JITVariable::KIND_VARIABLE === $ninetyfourthVar->kind
                ? $context->builder->load($ninetyfourthVar->value)
                : $ninetyfourthVar->value;
            $ov55Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyfourth_ov');
            $ok55Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyfourth_ok');
            $context->builder->branchIf($ov55, $ov55Block, $ok55Block);

            $context->builder->positionAtEnd($ov55Block);
            $ninetyfourthF55 = $context->builder->load($ninetyfourthVar->longArithOverflowDoubleSlot);
            $nF55 = $context->builder->siToFp($n, $f64);
            $hundredthF55 = $context->builder->fmul($ninetyfourthF55, $nF55);
            $hundredthF55 = $context->builder->fmul($hundredthF55, $nF55);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF55
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok55Block);

            $ninetyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyfourthLong,
                $n
            );
            $ov56 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyfifthVar->longArithOverflowFlag);
            if (null === $ov56 || null === $ninetyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetyfifth)');
            }
            $ninetyfifthLong = JITVariable::KIND_VARIABLE === $ninetyfifthVar->kind
                ? $context->builder->load($ninetyfifthVar->value)
                : $ninetyfifthVar->value;
            $ov56Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyfifth_ov');
            $ok56Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyfifth_ok');
            $context->builder->branchIf($ov56, $ov56Block, $ok56Block);

            $context->builder->positionAtEnd($ov56Block);
            $ninetyfifthF56 = $context->builder->load($ninetyfifthVar->longArithOverflowDoubleSlot);
            $nF56 = $context->builder->siToFp($n, $f64);
            $hundredthF56 = $context->builder->fmul($ninetyfifthF56, $nF56);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF56
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok56Block);
            $ninetysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyfifthLong,
                $n
            );
            $ov57 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetysixthVar->longArithOverflowFlag);
            if (null === $ov57 || null === $ninetysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetysixth)');
            }
            $ninetysixthLong = JITVariable::KIND_VARIABLE === $ninetysixthVar->kind
                ? $context->builder->load($ninetysixthVar->value)
                : $ninetysixthVar->value;
            $ov57Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetysixth_ov');
            $ok57Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetysixth_ok');
            $context->builder->branchIf($ov57, $ov57Block, $ok57Block);

            $context->builder->positionAtEnd($ov57Block);
            $ninetysixthF57 = $context->builder->load($ninetysixthVar->longArithOverflowDoubleSlot);
            $nF57 = $context->builder->siToFp($n, $f64);
            $hundredthF57 = $context->builder->fmul($ninetysixthF57, $nF57);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF57
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok57Block);
            $ninetyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetysixthLong,
                $n
            );
            $ov58 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyseventhVar->longArithOverflowFlag);
            if (null === $ov58 || null === $ninetyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetyseventh)');
            }
            $ninetyseventhLong = JITVariable::KIND_VARIABLE === $ninetyseventhVar->kind
                ? $context->builder->load($ninetyseventhVar->value)
                : $ninetyseventhVar->value;
            $ov58Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyseventh_ov');
            $ok58Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyseventh_ok');
            $context->builder->branchIf($ov58, $ov58Block, $ok58Block);

            $context->builder->positionAtEnd($ov58Block);
            $ninetyseventhF58 = $context->builder->load($ninetyseventhVar->longArithOverflowDoubleSlot);
            $nF58 = $context->builder->siToFp($n, $f64);
            $hundredthF58 = $context->builder->fmul($ninetyseventhF58, $nF58);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF58
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok58Block);
            $ninetyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyseventhLong,
                $n
            );
            $ov59 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyeighthVar->longArithOverflowFlag);
            if (null === $ov59 || null === $ninetyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetyeighth)');
            }
            $ninetyeighthLong = JITVariable::KIND_VARIABLE === $ninetyeighthVar->kind
                ? $context->builder->load($ninetyeighthVar->value)
                : $ninetyeighthVar->value;
            $ov59Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyeighth_ov');
            $ok59Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyeighth_ok');
            $context->builder->branchIf($ov59, $ov59Block, $ok59Block);

            $context->builder->positionAtEnd($ov59Block);
            $ninetyeighthF59 = $context->builder->load($ninetyeighthVar->longArithOverflowDoubleSlot);
            $nF59 = $context->builder->siToFp($n, $f64);
            $hundredthF59 = $context->builder->fmul($ninetyeighthF59, $nF59);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF59
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok59Block);
            $ninetyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyeighthLong,
                $n
            );
            $ov60 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyninthVar->longArithOverflowFlag);
            if (null === $ov60 || null === $ninetyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **100 expected smul overflow metadata (ninetyninth)');
            }
            $ninetyninthLong = JITVariable::KIND_VARIABLE === $ninetyninthVar->kind
                ? $context->builder->load($ninetyninthVar->value)
                : $ninetyninthVar->value;
            $ov60Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyninth_ov');
            $ok60Block = BasicBlockHelper::append($context, 'pow_hundredth_ninetyninth_ok');
            $context->builder->branchIf($ov60, $ov60Block, $ok60Block);

            $context->builder->positionAtEnd($ov60Block);
            $ninetyninthF60 = $context->builder->load($ninetyninthVar->longArithOverflowDoubleSlot);
            $nF60 = $context->builder->siToFp($n, $f64);
            $hundredthF60 = $context->builder->fmul($ninetyninthF60, $nF60);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredthF60
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok60Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $ninetyninthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('hundredfirst' === $expFold) {
            // n^101 = hundredth*n; overflow arms +1 ×nF vs **100.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **101.
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_hundredfirst_done');
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
            $hundredfirstFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $hundredfirstFEighth = $context->builder->fmul($hundredfirstFSq, $sqF);
            $hundredfirstF = $context->builder->fmul($hundredfirstFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
                $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
                    $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
                        $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
                        $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
                        $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
                        $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
                        $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
                        $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
                        $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);

            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $hundredfirstF = $context->builder->fmul($hundredfirstF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_hundredfirst_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_hundredfirst_cu_ok');
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
            $hundredfirstF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $hundredfirstF2Eighth = $context->builder->fmul($hundredfirstF2Sq, $sqF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
                $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
                    $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
                        $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
                        $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
                        $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
                        $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
                        $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
                        $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
                        $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);

            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $hundredfirstF2 = $context->builder->fmul($hundredfirstF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF2
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $hundredfirstF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $hundredfirstF3Eighth = $context->builder->fmul($hundredfirstF3Sq, $sqF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
                $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
                    $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
                        $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
                        $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
                        $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
                        $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
                        $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
                        $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
                        $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);

            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $hundredfirstF3 = $context->builder->fmul($hundredfirstF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF3
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_hundredfirst_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_hundredfirst_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $hundredfirstF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $hundredfirstF4Eighth = $context->builder->fmul($hundredfirstF4Sq, $sqF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
                $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
                    $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
                        $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
                        $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
                        $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
                        $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
                        $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
                        $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
                        $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);

            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $hundredfirstF4 = $context->builder->fmul($hundredfirstF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF4
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_hundredfirst_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_hundredfirst_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $hundredfirstF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $hundredfirstF5Eighth = $context->builder->fmul($hundredfirstF5Sq, $sqF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
                $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
                    $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
                        $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
                        $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
                        $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
                        $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
                        $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
                        $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
                        $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);

            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $hundredfirstF5 = $context->builder->fmul($hundredfirstF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF5
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $hundredfirstF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $hundredfirstF6Eighth = $context->builder->fmul($hundredfirstF6Sq, $sqF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
                $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
                    $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
                        $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
                        $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
                        $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
                        $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
                        $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
                        $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
                        $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);

            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $hundredfirstF6 = $context->builder->fmul($hundredfirstF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF6
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $hundredfirstF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $hundredfirstF7Eighth = $context->builder->fmul($hundredfirstF7Sq, $sqF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
                $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
                    $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
                        $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
                        $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
                        $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
                        $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
                        $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
                        $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
                        $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);

            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $hundredfirstF7 = $context->builder->fmul($hundredfirstF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF7
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $hundredfirstF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $hundredfirstF8Eighth = $context->builder->fmul($hundredfirstF8Sq, $sqF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
                $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
                    $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
                        $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
                        $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
                        $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
                        $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
                        $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
                        $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
                        $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);

            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $hundredfirstF8 = $context->builder->fmul($hundredfirstF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF8
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $hundredfirstF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
                $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
                    $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
                        $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
                        $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
                        $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
                        $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
                        $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
                        $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
                        $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);

            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $hundredfirstF9 = $context->builder->fmul($hundredfirstF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF9
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $hundredfirstF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
                $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
                    $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
                        $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
                        $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
                        $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
                        $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
                        $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
                        $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
                        $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);

            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $hundredfirstF10 = $context->builder->fmul($hundredfirstF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF10
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $hundredfirstF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
                $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
                    $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
                        $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
                        $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
                        $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
                        $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
                        $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
                        $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
                        $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);

            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $hundredfirstF11 = $context->builder->fmul($hundredfirstF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF11
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $hundredfirstF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
                $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
                    $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
                        $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
                        $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
                        $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
                        $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
                        $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
                        $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
                        $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);

            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $hundredfirstF12 = $context->builder->fmul($hundredfirstF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF12
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $hundredfirstF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
                $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
                    $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
                        $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
                        $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
                        $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
                        $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
                        $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
                        $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
                        $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);

            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $hundredfirstF13 = $context->builder->fmul($hundredfirstF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF13
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $hundredfirstF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
                $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
                    $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
                        $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
                        $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
                        $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
                        $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
                        $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
                        $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
                        $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);

            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $hundredfirstF14 = $context->builder->fmul($hundredfirstF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF14
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $hundredfirstF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
                $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
                    $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
                        $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
                        $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
                        $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
                        $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
                        $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
                        $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
                        $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);

            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $hundredfirstF15 = $context->builder->fmul($hundredfirstF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF15
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $hundredfirstF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
                $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
                    $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
                        $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
                        $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
                        $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
                        $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
                        $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
                        $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
                        $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);

            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $hundredfirstF16 = $context->builder->fmul($hundredfirstF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF16
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $hundredfirstF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
                $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
                    $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
                        $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
                        $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
                        $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
                        $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
                        $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
                        $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
                        $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);

            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $hundredfirstF17 = $context->builder->fmul($hundredfirstF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF17
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $hundredfirstF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
                $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
                    $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
                        $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
                        $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
                        $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
                        $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
                        $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
                        $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
                        $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);

            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $hundredfirstF18 = $context->builder->fmul($hundredfirstF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF18
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $hundredfirstF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
                $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
                    $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
                        $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
                        $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
                        $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
                        $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
                        $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
                        $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
                        $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);

            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $hundredfirstF19 = $context->builder->fmul($hundredfirstF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF19
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_hundredfirst_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $hundredfirstF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
                    $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
                        $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
                        $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
                        $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
                        $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
                        $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
                        $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
                        $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);

            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $hundredfirstF20 = $context->builder->fmul($hundredfirstF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF20
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $hundredfirstF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
                    $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
                    $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
                    $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
                    $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
                    $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
                    $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
                    $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);

            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $hundredfirstF21 = $context->builder->fmul($hundredfirstF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF21
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $hundredfirstF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
                $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
                $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
                $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
                $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
                $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
                $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);

            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $hundredfirstF22 = $context->builder->fmul($hundredfirstF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF22
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $hundredfirstF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);

            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $hundredfirstF23 = $context->builder->fmul($hundredfirstF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF23
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $hundredfirstF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);

            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $hundredfirstF24 = $context->builder->fmul($hundredfirstF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF24
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $hundredfirstF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);

            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $hundredfirstF25 = $context->builder->fmul($hundredfirstF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF25
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $hundredfirstF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);

            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $hundredfirstF26 = $context->builder->fmul($hundredfirstF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF26
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $hundredfirstF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);

            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $hundredfirstF27 = $context->builder->fmul($hundredfirstF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF27
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $hundredfirstF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);

            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $hundredfirstF28 = $context->builder->fmul($hundredfirstF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF28
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $hundredfirstF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);

            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $hundredfirstF29 = $context->builder->fmul($hundredfirstF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF29
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_hundredfirst_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $hundredfirstF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $hundredfirstF30 = $context->builder->fmul($hundredfirstF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF30
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $hundredfirstF31 = $context->builder->fmul($seventiethF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $hundredfirstF31 = $context->builder->fmul($hundredfirstF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF31
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $hundredfirstF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $hundredfirstF32 = $context->builder->fmul($hundredfirstF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF32
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $hundredfirstF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $hundredfirstF33 = $context->builder->fmul($hundredfirstF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF33
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $hundredfirstF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $hundredfirstF34 = $context->builder->fmul($hundredfirstF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF34
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $hundredfirstF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $hundredfirstF35 = $context->builder->fmul($hundredfirstF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF35
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $hundredfirstF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $hundredfirstF36 = $context->builder->fmul($hundredfirstF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF36
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $hundredfirstF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $hundredfirstF37 = $context->builder->fmul($hundredfirstF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF37
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $hundredfirstF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $hundredfirstF38 = $context->builder->fmul($hundredfirstF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF38
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $hundredfirstF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $hundredfirstF39 = $context->builder->fmul($hundredfirstF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF39
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_hundredfirst_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $hundredfirstF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $hundredfirstF40 = $context->builder->fmul($hundredfirstF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF40
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $hundredfirstF41 = $context->builder->fmul($eightiethF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $hundredfirstF41 = $context->builder->fmul($hundredfirstF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF41
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $hundredfirstF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $hundredfirstF42 = $context->builder->fmul($hundredfirstF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF42
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $hundredfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($hundredfirstF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($hundredfirstF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($hundredfirstF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($hundredfirstF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($hundredfirstF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($hundredfirstF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($hundredfirstF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($hundredfirstF43, $nF43);
            $hundredfirstF43 = $context->builder->fmul($hundredfirstF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF43
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $hundredfirstF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($hundredfirstF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($hundredfirstF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($hundredfirstF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($hundredfirstF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($hundredfirstF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($hundredfirstF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($hundredfirstF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($hundredfirstF44, $nF44);
            $hundredfirstF44 = $context->builder->fmul($hundredfirstF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF44
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $hundredfirstF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($hundredfirstF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($hundredfirstF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($hundredfirstF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($hundredfirstF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($hundredfirstF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($hundredfirstF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($hundredfirstF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($hundredfirstF45, $nF45);
            $hundredfirstF45 = $context->builder->fmul($hundredfirstF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF45
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $hundredfirstF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($hundredfirstF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($hundredfirstF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($hundredfirstF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($hundredfirstF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($hundredfirstF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($hundredfirstF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($hundredfirstF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($hundredfirstF46, $nF46);
            $hundredfirstF46 = $context->builder->fmul($hundredfirstF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF46
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $hundredfirstF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $hundredfirstF47 = $context->builder->fmul($hundredfirstF47, $nF47);
            $hundredfirstF47 = $context->builder->fmul($hundredfirstF47, $nF47);
            $hundredfirstF47 = $context->builder->fmul($hundredfirstF47, $nF47);
            $hundredfirstF47 = $context->builder->fmul($hundredfirstF47, $nF47);
            $hundredfirstF47 = $context->builder->fmul($hundredfirstF47, $nF47);
            $hundredfirstF47 = $context->builder->fmul($hundredfirstF47, $nF47);
            $hundredfirstF47 = $context->builder->fmul($hundredfirstF47, $nF47);
            $hundredfirstF47 = $context->builder->fmul($hundredfirstF47, $nF47);
            $hundredfirstF47 = $context->builder->fmul($hundredfirstF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF47
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $hundredfirstF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $hundredfirstF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $hundredfirstF48 = $context->builder->fmul($hundredfirstF48, $nF48);
            $hundredfirstF48 = $context->builder->fmul($hundredfirstF48, $nF48);
            $hundredfirstF48 = $context->builder->fmul($hundredfirstF48, $nF48);
            $hundredfirstF48 = $context->builder->fmul($hundredfirstF48, $nF48);
            $hundredfirstF48 = $context->builder->fmul($hundredfirstF48, $nF48);
            $hundredfirstF48 = $context->builder->fmul($hundredfirstF48, $nF48);
            $hundredfirstF48 = $context->builder->fmul($hundredfirstF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF48
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $hundredfirstF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $hundredfirstF49 = $context->builder->fmul($hundredfirstF49, $nF49);
            $hundredfirstF49 = $context->builder->fmul($hundredfirstF49, $nF49);
            $hundredfirstF49 = $context->builder->fmul($hundredfirstF49, $nF49);
            $hundredfirstF49 = $context->builder->fmul($hundredfirstF49, $nF49);
            $hundredfirstF49 = $context->builder->fmul($hundredfirstF49, $nF49);
            $hundredfirstF49 = $context->builder->fmul($hundredfirstF49, $nF49);
            $hundredfirstF49 = $context->builder->fmul($hundredfirstF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF49
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (eightyninth)');
            }
            $eightyninthLong = JITVariable::KIND_VARIABLE === $eightyninthVar->kind
                ? $context->builder->load($eightyninthVar->value)
                : $eightyninthVar->value;
            $ov50Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyninth_ov');
            $ok50Block = BasicBlockHelper::append($context, 'pow_hundredfirst_eightyninth_ok');
            $context->builder->branchIf($ov50, $ov50Block, $ok50Block);

            $context->builder->positionAtEnd($ov50Block);
            $eightyninthF50 = $context->builder->load($eightyninthVar->longArithOverflowDoubleSlot);
            $nF50 = $context->builder->siToFp($n, $f64);
            $hundredfirstF50 = $context->builder->fmul($eightyninthF50, $nF50);
            $hundredfirstF50 = $context->builder->fmul($hundredfirstF50, $nF50);
            $hundredfirstF50 = $context->builder->fmul($hundredfirstF50, $nF50);
            $hundredfirstF50 = $context->builder->fmul($hundredfirstF50, $nF50);
            $hundredfirstF50 = $context->builder->fmul($hundredfirstF50, $nF50);
            $hundredfirstF50 = $context->builder->fmul($hundredfirstF50, $nF50);
            $hundredfirstF50 = $context->builder->fmul($hundredfirstF50, $nF50);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF50
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetieth)');
            }
            $ninetiethLong = JITVariable::KIND_VARIABLE === $ninetiethVar->kind
                ? $context->builder->load($ninetiethVar->value)
                : $ninetiethVar->value;
            $ov51Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetieth_ov');
            $ok51Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetieth_ok');
            $context->builder->branchIf($ov51, $ov51Block, $ok51Block);

            $context->builder->positionAtEnd($ov51Block);
            $ninetiethF51 = $context->builder->load($ninetiethVar->longArithOverflowDoubleSlot);
            $nF51 = $context->builder->siToFp($n, $f64);
            $hundredfirstF51 = $context->builder->fmul($ninetiethF51, $nF51);
            $hundredfirstF51 = $context->builder->fmul($hundredfirstF51, $nF51);
            $hundredfirstF51 = $context->builder->fmul($hundredfirstF51, $nF51);
            $hundredfirstF51 = $context->builder->fmul($hundredfirstF51, $nF51);
            $hundredfirstF51 = $context->builder->fmul($hundredfirstF51, $nF51);
            $hundredfirstF51 = $context->builder->fmul($hundredfirstF51, $nF51);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF51
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetyfirst)');
            }
            $ninetyfirstLong = JITVariable::KIND_VARIABLE === $ninetyfirstVar->kind
                ? $context->builder->load($ninetyfirstVar->value)
                : $ninetyfirstVar->value;
            $ov52Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyfirst_ov');
            $ok52Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyfirst_ok');
            $context->builder->branchIf($ov52, $ov52Block, $ok52Block);

            $context->builder->positionAtEnd($ov52Block);
            $ninetyfirstF52 = $context->builder->load($ninetyfirstVar->longArithOverflowDoubleSlot);
            $nF52 = $context->builder->siToFp($n, $f64);
            $hundredfirstF52 = $context->builder->fmul($ninetyfirstF52, $nF52);
            $hundredfirstF52 = $context->builder->fmul($hundredfirstF52, $nF52);
            $hundredfirstF52 = $context->builder->fmul($hundredfirstF52, $nF52);
            $hundredfirstF52 = $context->builder->fmul($hundredfirstF52, $nF52);
            $hundredfirstF52 = $context->builder->fmul($hundredfirstF52, $nF52);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF52
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetysecond)');
            }
            $ninetysecondLong = JITVariable::KIND_VARIABLE === $ninetysecondVar->kind
                ? $context->builder->load($ninetysecondVar->value)
                : $ninetysecondVar->value;
            $ov53Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetysecond_ov');
            $ok53Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetysecond_ok');
            $context->builder->branchIf($ov53, $ov53Block, $ok53Block);

            $context->builder->positionAtEnd($ov53Block);
            $ninetysecondF53 = $context->builder->load($ninetysecondVar->longArithOverflowDoubleSlot);
            $nF53 = $context->builder->siToFp($n, $f64);
            $hundredfirstF53 = $context->builder->fmul($ninetysecondF53, $nF53);
            $hundredfirstF53 = $context->builder->fmul($hundredfirstF53, $nF53);
            $hundredfirstF53 = $context->builder->fmul($hundredfirstF53, $nF53);
            $hundredfirstF53 = $context->builder->fmul($hundredfirstF53, $nF53);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF53
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
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetythird)');
            }
            $ninetythirdLong = JITVariable::KIND_VARIABLE === $ninetythirdVar->kind
                ? $context->builder->load($ninetythirdVar->value)
                : $ninetythirdVar->value;
            $ov54Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetythird_ov');
            $ok54Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetythird_ok');
            $context->builder->branchIf($ov54, $ov54Block, $ok54Block);

            $context->builder->positionAtEnd($ov54Block);
            $ninetythirdF54 = $context->builder->load($ninetythirdVar->longArithOverflowDoubleSlot);
            $nF54 = $context->builder->siToFp($n, $f64);
            $hundredfirstF54 = $context->builder->fmul($ninetythirdF54, $nF54);
            $hundredfirstF54 = $context->builder->fmul($hundredfirstF54, $nF54);
            $hundredfirstF54 = $context->builder->fmul($hundredfirstF54, $nF54);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF54
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok54Block);
            $ninetyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetythirdLong,
                $n
            );
            $ov55 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyfourthVar->longArithOverflowFlag);
            if (null === $ov55 || null === $ninetyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetyfourth)');
            }
            $ninetyfourthLong = JITVariable::KIND_VARIABLE === $ninetyfourthVar->kind
                ? $context->builder->load($ninetyfourthVar->value)
                : $ninetyfourthVar->value;
            $ov55Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyfourth_ov');
            $ok55Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyfourth_ok');
            $context->builder->branchIf($ov55, $ov55Block, $ok55Block);

            $context->builder->positionAtEnd($ov55Block);
            $ninetyfourthF55 = $context->builder->load($ninetyfourthVar->longArithOverflowDoubleSlot);
            $nF55 = $context->builder->siToFp($n, $f64);
            $hundredfirstF55 = $context->builder->fmul($ninetyfourthF55, $nF55);
            $hundredfirstF55 = $context->builder->fmul($hundredfirstF55, $nF55);
            $hundredfirstF55 = $context->builder->fmul($hundredfirstF55, $nF55);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF55
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok55Block);

            $ninetyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyfourthLong,
                $n
            );
            $ov56 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyfifthVar->longArithOverflowFlag);
            if (null === $ov56 || null === $ninetyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetyfifth)');
            }
            $ninetyfifthLong = JITVariable::KIND_VARIABLE === $ninetyfifthVar->kind
                ? $context->builder->load($ninetyfifthVar->value)
                : $ninetyfifthVar->value;
            $ov56Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyfifth_ov');
            $ok56Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyfifth_ok');
            $context->builder->branchIf($ov56, $ov56Block, $ok56Block);

            $context->builder->positionAtEnd($ov56Block);
            $ninetyfifthF56 = $context->builder->load($ninetyfifthVar->longArithOverflowDoubleSlot);
            $nF56 = $context->builder->siToFp($n, $f64);
            $hundredfirstF56 = $context->builder->fmul($ninetyfifthF56, $nF56);
            $hundredfirstF56 = $context->builder->fmul($hundredfirstF56, $nF56);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF56
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok56Block);
            $ninetysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyfifthLong,
                $n
            );
            $ov57 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetysixthVar->longArithOverflowFlag);
            if (null === $ov57 || null === $ninetysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetysixth)');
            }
            $ninetysixthLong = JITVariable::KIND_VARIABLE === $ninetysixthVar->kind
                ? $context->builder->load($ninetysixthVar->value)
                : $ninetysixthVar->value;
            $ov57Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetysixth_ov');
            $ok57Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetysixth_ok');
            $context->builder->branchIf($ov57, $ov57Block, $ok57Block);

            $context->builder->positionAtEnd($ov57Block);
            $ninetysixthF57 = $context->builder->load($ninetysixthVar->longArithOverflowDoubleSlot);
            $nF57 = $context->builder->siToFp($n, $f64);
            $hundredfirstF57 = $context->builder->fmul($ninetysixthF57, $nF57);
            $hundredfirstF57 = $context->builder->fmul($hundredfirstF57, $nF57);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF57
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok57Block);
            $ninetyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetysixthLong,
                $n
            );
            $ov58 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyseventhVar->longArithOverflowFlag);
            if (null === $ov58 || null === $ninetyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetyseventh)');
            }
            $ninetyseventhLong = JITVariable::KIND_VARIABLE === $ninetyseventhVar->kind
                ? $context->builder->load($ninetyseventhVar->value)
                : $ninetyseventhVar->value;
            $ov58Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyseventh_ov');
            $ok58Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyseventh_ok');
            $context->builder->branchIf($ov58, $ov58Block, $ok58Block);

            $context->builder->positionAtEnd($ov58Block);
            $ninetyseventhF58 = $context->builder->load($ninetyseventhVar->longArithOverflowDoubleSlot);
            $nF58 = $context->builder->siToFp($n, $f64);
            $hundredfirstF58 = $context->builder->fmul($ninetyseventhF58, $nF58);
            $hundredfirstF58 = $context->builder->fmul($hundredfirstF58, $nF58);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF58
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok58Block);
            $ninetyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyseventhLong,
                $n
            );
            $ov59 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyeighthVar->longArithOverflowFlag);
            if (null === $ov59 || null === $ninetyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetyeighth)');
            }
            $ninetyeighthLong = JITVariable::KIND_VARIABLE === $ninetyeighthVar->kind
                ? $context->builder->load($ninetyeighthVar->value)
                : $ninetyeighthVar->value;
            $ov59Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyeighth_ov');
            $ok59Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyeighth_ok');
            $context->builder->branchIf($ov59, $ov59Block, $ok59Block);

            $context->builder->positionAtEnd($ov59Block);
            $ninetyeighthF59 = $context->builder->load($ninetyeighthVar->longArithOverflowDoubleSlot);
            $nF59 = $context->builder->siToFp($n, $f64);
            $hundredfirstF59 = $context->builder->fmul($ninetyeighthF59, $nF59);
            $hundredfirstF59 = $context->builder->fmul($hundredfirstF59, $nF59);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF59
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok59Block);
            $ninetyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyeighthLong,
                $n
            );
            $ov60 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyninthVar->longArithOverflowFlag);
            if (null === $ov60 || null === $ninetyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **101 expected smul overflow metadata (ninetyninth)');
            }
            $ninetyninthLong = JITVariable::KIND_VARIABLE === $ninetyninthVar->kind
                ? $context->builder->load($ninetyninthVar->value)
                : $ninetyninthVar->value;
            $ov60Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyninth_ov');
            $ok60Block = BasicBlockHelper::append($context, 'pow_hundredfirst_ninetyninth_ok');
            $context->builder->branchIf($ov60, $ov60Block, $ok60Block);

            $context->builder->positionAtEnd($ov60Block);
            $ninetyninthF60 = $context->builder->load($ninetyninthVar->longArithOverflowDoubleSlot);
            $nF60 = $context->builder->siToFp($n, $f64);
            $hundredfirstF60 = $context->builder->fmul($ninetyninthF60, $nF60);
            $hundredfirstF60 = $context->builder->fmul($hundredfirstF60, $nF60);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF60
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok60Block);
            $hundredthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyninthLong,
                $n
            );
            $ov61 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredthVar->longArithOverflowFlag);
            if (null === $ov61 || null === $hundredthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **101 expected smul overflow metadata (hundredth)');
            }
            $hundredthLong = JITVariable::KIND_VARIABLE === $hundredthVar->kind
                ? $context->builder->load($hundredthVar->value)
                : $hundredthVar->value;
            $ov61Block = BasicBlockHelper::append($context, 'pow_hundredfirst_hundredth_ov');
            $ok61Block = BasicBlockHelper::append($context, 'pow_hundredfirst_hundredth_ok');
            $context->builder->branchIf($ov61, $ov61Block, $ok61Block);

            $context->builder->positionAtEnd($ov61Block);
            $hundredthF61 = $context->builder->load($hundredthVar->longArithOverflowDoubleSlot);
            $nF61 = $context->builder->siToFp($n, $f64);
            $hundredfirstF61 = $context->builder->fmul($hundredthF61, $nF61);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfirstF61
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok61Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $hundredthLong,
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
