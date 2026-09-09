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
 * emit (hundredfifth+) (#36387 / #36386).
 *
 * hundredth…hundredfourth live in {@see JitPowIntegerEmitExponents100to104}.
 * This TU holds further **N appends without growing the 100–104 file.
 *
 * No new C ABI. php-src: Zend/zend_operators.c {@code pow_function} /
 * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
 * {@code PHP_FUNCTION(pow)}.
 */
final class JitPowIntegerEmitHigh
{
    /**
     * @return bool true when {@code $expFold} was a high (105+) exponent and emit ran
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
            'hundredfifth' => true,
            'hundredsixth' => true,
        ];
        if (!isset($high[$expFold])) {
            return false;
        }

        if ('hundredfifth' === $expFold) {
            // n^105 = hundredfourth*n; overflow arms +1 ×nF vs **104.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **105.
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_hundredfifth_done');
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
            $hundredfifthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $hundredfifthFEighth = $context->builder->fmul($hundredfifthFSq, $sqF);
            $hundredfifthF = $context->builder->fmul($hundredfifthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
                $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
                    $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
                        $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
                        $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
                        $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
                        $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
                        $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
                        $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
                        $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);

            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $hundredfifthF = $context->builder->fmul($hundredfifthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_hundredfifth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_hundredfifth_cu_ok');
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
            $hundredfifthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $hundredfifthF2Eighth = $context->builder->fmul($hundredfifthF2Sq, $sqF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
                $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
                    $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
                        $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
                        $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
                        $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
                        $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
                        $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
                        $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
                        $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);

            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF2);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF);
            $hundredfifthF2 = $context->builder->fmul($hundredfifthF2, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF2
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $hundredfifthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $hundredfifthF3Eighth = $context->builder->fmul($hundredfifthF3Sq, $sqF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
                $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
                    $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
                        $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
                        $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
                        $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
                        $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
                        $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
                        $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
                        $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);

            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF3);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF);
            $hundredfifthF3 = $context->builder->fmul($hundredfifthF3, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF3
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_hundredfifth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_hundredfifth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $hundredfifthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $hundredfifthF4Eighth = $context->builder->fmul($hundredfifthF4Sq, $sqF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
                $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
                    $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
                        $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
                        $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
                        $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
                        $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
                        $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
                        $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
                        $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);

            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF4);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF);
            $hundredfifthF4 = $context->builder->fmul($hundredfifthF4, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF4
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_hundredfifth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_hundredfifth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $hundredfifthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $hundredfifthF5Eighth = $context->builder->fmul($hundredfifthF5Sq, $sqF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
                $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
                    $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
                        $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
                        $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
                        $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
                        $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
                        $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
                        $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
                        $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);

            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF5);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF);
            $hundredfifthF5 = $context->builder->fmul($hundredfifthF5, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF5
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $hundredfifthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $hundredfifthF6Eighth = $context->builder->fmul($hundredfifthF6Sq, $sqF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
                $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
                    $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
                        $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
                        $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
                        $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
                        $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
                        $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
                        $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
                        $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);

            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF6);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF);
            $hundredfifthF6 = $context->builder->fmul($hundredfifthF6, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF6
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $hundredfifthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $hundredfifthF7Eighth = $context->builder->fmul($hundredfifthF7Sq, $sqF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
                $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
                    $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
                        $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
                        $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
                        $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
                        $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
                        $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
                        $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
                        $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);

            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF7);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF);
            $hundredfifthF7 = $context->builder->fmul($hundredfifthF7, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF7
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $hundredfifthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $hundredfifthF8Eighth = $context->builder->fmul($hundredfifthF8Sq, $sqF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
                $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
                    $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
                        $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
                        $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
                        $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
                        $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
                        $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
                        $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
                        $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);

            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF8);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF);
            $hundredfifthF8 = $context->builder->fmul($hundredfifthF8, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF8
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $hundredfifthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
                $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
                    $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
                        $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
                        $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
                        $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
                        $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
                        $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
                        $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
                        $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);

            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF9);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF);
            $hundredfifthF9 = $context->builder->fmul($hundredfifthF9, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF9
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $hundredfifthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
                $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
                    $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
                        $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
                        $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
                        $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
                        $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
                        $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
                        $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
                        $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);

            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF10);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF);
            $hundredfifthF10 = $context->builder->fmul($hundredfifthF10, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF10
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $hundredfifthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
                $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
                    $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
                        $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
                        $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
                        $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
                        $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
                        $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
                        $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
                        $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);

            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF11);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF);
            $hundredfifthF11 = $context->builder->fmul($hundredfifthF11, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF11
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $hundredfifthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
                $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
                    $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
                        $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
                        $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
                        $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
                        $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
                        $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
                        $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
                        $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);

            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF12);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF);
            $hundredfifthF12 = $context->builder->fmul($hundredfifthF12, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF12
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $hundredfifthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
                $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
                    $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
                        $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
                        $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
                        $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
                        $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
                        $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
                        $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
                        $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);

            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF13);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF);
            $hundredfifthF13 = $context->builder->fmul($hundredfifthF13, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF13
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $hundredfifthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
                $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
                    $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
                        $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
                        $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
                        $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
                        $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
                        $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
                        $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
                        $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);

            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF14);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF);
            $hundredfifthF14 = $context->builder->fmul($hundredfifthF14, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF14
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $hundredfifthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
                $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
                    $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
                        $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
                        $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
                        $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
                        $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
                        $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
                        $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
                        $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);

            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF15);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF);
            $hundredfifthF15 = $context->builder->fmul($hundredfifthF15, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF15
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $hundredfifthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
                $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
                    $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
                        $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
                        $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
                        $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
                        $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
                        $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
                        $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
                        $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);

            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF16);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF);
            $hundredfifthF16 = $context->builder->fmul($hundredfifthF16, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF16
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $hundredfifthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
                $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
                    $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
                        $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
                        $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
                        $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
                        $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
                        $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
                        $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
                        $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);

            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF17);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF);
            $hundredfifthF17 = $context->builder->fmul($hundredfifthF17, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF17
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $hundredfifthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
                $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
                    $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
                        $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
                        $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
                        $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
                        $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
                        $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
                        $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
                        $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);

            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF18);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF);
            $hundredfifthF18 = $context->builder->fmul($hundredfifthF18, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF18
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $hundredfifthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
                $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
                    $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
                        $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
                        $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
                        $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
                        $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
                        $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
                        $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
                        $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);

            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF19);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF);
            $hundredfifthF19 = $context->builder->fmul($hundredfifthF19, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF19
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_hundredfifth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $hundredfifthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
                    $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
                        $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
                        $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
                        $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
                        $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
                        $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
                        $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
                        $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);

            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF20);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF);
            $hundredfifthF20 = $context->builder->fmul($hundredfifthF20, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF20
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $hundredfifthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
                    $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
                    $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
                    $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
                    $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
                    $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
                    $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
                    $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);

            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF21);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF);
            $hundredfifthF21 = $context->builder->fmul($hundredfifthF21, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF21
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $hundredfifthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
                $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
                $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
                $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
                $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
                $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
                $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);

            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF22);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF);
            $hundredfifthF22 = $context->builder->fmul($hundredfifthF22, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF22
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $hundredfifthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);

            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF23);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF);
            $hundredfifthF23 = $context->builder->fmul($hundredfifthF23, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF23
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $hundredfifthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);

            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF24);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF);
            $hundredfifthF24 = $context->builder->fmul($hundredfifthF24, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF24
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $hundredfifthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);

            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF25);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF);
            $hundredfifthF25 = $context->builder->fmul($hundredfifthF25, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF25
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $hundredfifthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);

            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF26);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF);
            $hundredfifthF26 = $context->builder->fmul($hundredfifthF26, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF26
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $hundredfifthF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);

            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF27);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF);
            $hundredfifthF27 = $context->builder->fmul($hundredfifthF27, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF27
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $hundredfifthF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);

            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF28);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF);
            $hundredfifthF28 = $context->builder->fmul($hundredfifthF28, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF28
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $hundredfifthF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);

            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF29);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF);
            $hundredfifthF29 = $context->builder->fmul($hundredfifthF29, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF29
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_hundredfifth_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $hundredfifthF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF30);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF);
            $hundredfifthF30 = $context->builder->fmul($hundredfifthF30, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF30
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $hundredfifthF31 = $context->builder->fmul($seventiethF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF31);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF);
            $hundredfifthF31 = $context->builder->fmul($hundredfifthF31, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF31
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $hundredfifthF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF32);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF);
            $hundredfifthF32 = $context->builder->fmul($hundredfifthF32, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF32
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $hundredfifthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF33);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF);
            $hundredfifthF33 = $context->builder->fmul($hundredfifthF33, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF33
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $hundredfifthF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF34);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF);
            $hundredfifthF34 = $context->builder->fmul($hundredfifthF34, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF34
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $hundredfifthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF35);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF);
            $hundredfifthF35 = $context->builder->fmul($hundredfifthF35, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF35
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $hundredfifthF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF36);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF);
            $hundredfifthF36 = $context->builder->fmul($hundredfifthF36, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF36
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $hundredfifthF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF37);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF);
            $hundredfifthF37 = $context->builder->fmul($hundredfifthF37, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF37
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $hundredfifthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF38);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF);
            $hundredfifthF38 = $context->builder->fmul($hundredfifthF38, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF38
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $hundredfifthF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $hundredfifthF39 = $context->builder->fmul($hundredfifthF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF39
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_hundredfifth_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $hundredfifthF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $hundredfifthF40 = $context->builder->fmul($hundredfifthF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF40
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $hundredfifthF41 = $context->builder->fmul($eightiethF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $hundredfifthF41 = $context->builder->fmul($hundredfifthF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF41
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $hundredfifthF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $hundredfifthF42 = $context->builder->fmul($hundredfifthF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF42
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $hundredfifthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $hundredfifthF43 = $context->builder->fmul($hundredfifthF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF43
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $hundredfifthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $hundredfifthF44 = $context->builder->fmul($hundredfifthF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF44
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $hundredfifthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $hundredfifthF45 = $context->builder->fmul($hundredfifthF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF45
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $hundredfifthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $hundredfifthF46 = $context->builder->fmul($hundredfifthF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF46
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $hundredfifthF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $hundredfifthF47 = $context->builder->fmul($hundredfifthF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF47
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $hundredfifthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $hundredfifthF48 = $context->builder->fmul($hundredfifthF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF48
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $hundredfifthF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $hundredfifthF49 = $context->builder->fmul($hundredfifthF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF49
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (eightyninth)');
            }
            $eightyninthLong = JITVariable::KIND_VARIABLE === $eightyninthVar->kind
                ? $context->builder->load($eightyninthVar->value)
                : $eightyninthVar->value;
            $ov50Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyninth_ov');
            $ok50Block = BasicBlockHelper::append($context, 'pow_hundredfifth_eightyninth_ok');
            $context->builder->branchIf($ov50, $ov50Block, $ok50Block);

            $context->builder->positionAtEnd($ov50Block);
            $eightyninthF50 = $context->builder->load($eightyninthVar->longArithOverflowDoubleSlot);
            $nF50 = $context->builder->siToFp($n, $f64);
            $hundredfifthF50 = $context->builder->fmul($eightyninthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $hundredfifthF50 = $context->builder->fmul($hundredfifthF50, $nF50);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF50
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetieth)');
            }
            $ninetiethLong = JITVariable::KIND_VARIABLE === $ninetiethVar->kind
                ? $context->builder->load($ninetiethVar->value)
                : $ninetiethVar->value;
            $ov51Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetieth_ov');
            $ok51Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetieth_ok');
            $context->builder->branchIf($ov51, $ov51Block, $ok51Block);

            $context->builder->positionAtEnd($ov51Block);
            $ninetiethF51 = $context->builder->load($ninetiethVar->longArithOverflowDoubleSlot);
            $nF51 = $context->builder->siToFp($n, $f64);
            $hundredfifthF51 = $context->builder->fmul($ninetiethF51, $nF51);
            $hundredfifthF51 = $context->builder->fmul($hundredfifthF51, $nF51);
            $hundredfifthF51 = $context->builder->fmul($hundredfifthF51, $nF51);
            $hundredfifthF51 = $context->builder->fmul($hundredfifthF51, $nF51);
            $hundredfifthF51 = $context->builder->fmul($hundredfifthF51, $nF51);
            $hundredfifthF51 = $context->builder->fmul($hundredfifthF51, $nF51);
            $hundredfifthF51 = $context->builder->fmul($hundredfifthF51, $nF51);
            $hundredfifthF51 = $context->builder->fmul($hundredfifthF51, $nF51);
            $hundredfifthF51 = $context->builder->fmul($hundredfifthF51, $nF51);
            $hundredfifthF51 = $context->builder->fmul($hundredfifthF51, $nF51);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF51
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetyfirst)');
            }
            $ninetyfirstLong = JITVariable::KIND_VARIABLE === $ninetyfirstVar->kind
                ? $context->builder->load($ninetyfirstVar->value)
                : $ninetyfirstVar->value;
            $ov52Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyfirst_ov');
            $ok52Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyfirst_ok');
            $context->builder->branchIf($ov52, $ov52Block, $ok52Block);

            $context->builder->positionAtEnd($ov52Block);
            $ninetyfirstF52 = $context->builder->load($ninetyfirstVar->longArithOverflowDoubleSlot);
            $nF52 = $context->builder->siToFp($n, $f64);
            $hundredfifthF52 = $context->builder->fmul($ninetyfirstF52, $nF52);
            $hundredfifthF52 = $context->builder->fmul($hundredfifthF52, $nF52);
            $hundredfifthF52 = $context->builder->fmul($hundredfifthF52, $nF52);
            $hundredfifthF52 = $context->builder->fmul($hundredfifthF52, $nF52);
            $hundredfifthF52 = $context->builder->fmul($hundredfifthF52, $nF52);
            $hundredfifthF52 = $context->builder->fmul($hundredfifthF52, $nF52);
            $hundredfifthF52 = $context->builder->fmul($hundredfifthF52, $nF52);
            $hundredfifthF52 = $context->builder->fmul($hundredfifthF52, $nF52);
            $hundredfifthF52 = $context->builder->fmul($hundredfifthF52, $nF52);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF52
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetysecond)');
            }
            $ninetysecondLong = JITVariable::KIND_VARIABLE === $ninetysecondVar->kind
                ? $context->builder->load($ninetysecondVar->value)
                : $ninetysecondVar->value;
            $ov53Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetysecond_ov');
            $ok53Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetysecond_ok');
            $context->builder->branchIf($ov53, $ov53Block, $ok53Block);

            $context->builder->positionAtEnd($ov53Block);
            $ninetysecondF53 = $context->builder->load($ninetysecondVar->longArithOverflowDoubleSlot);
            $nF53 = $context->builder->siToFp($n, $f64);
            $hundredfifthF53 = $context->builder->fmul($ninetysecondF53, $nF53);
            $hundredfifthF53 = $context->builder->fmul($hundredfifthF53, $nF53);
            $hundredfifthF53 = $context->builder->fmul($hundredfifthF53, $nF53);
            $hundredfifthF53 = $context->builder->fmul($hundredfifthF53, $nF53);
            $hundredfifthF53 = $context->builder->fmul($hundredfifthF53, $nF53);
            $hundredfifthF53 = $context->builder->fmul($hundredfifthF53, $nF53);
            $hundredfifthF53 = $context->builder->fmul($hundredfifthF53, $nF53);
            $hundredfifthF53 = $context->builder->fmul($hundredfifthF53, $nF53);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF53
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetythird)');
            }
            $ninetythirdLong = JITVariable::KIND_VARIABLE === $ninetythirdVar->kind
                ? $context->builder->load($ninetythirdVar->value)
                : $ninetythirdVar->value;
            $ov54Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetythird_ov');
            $ok54Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetythird_ok');
            $context->builder->branchIf($ov54, $ov54Block, $ok54Block);

            $context->builder->positionAtEnd($ov54Block);
            $ninetythirdF54 = $context->builder->load($ninetythirdVar->longArithOverflowDoubleSlot);
            $nF54 = $context->builder->siToFp($n, $f64);
            $hundredfifthF54 = $context->builder->fmul($ninetythirdF54, $nF54);
            $hundredfifthF54 = $context->builder->fmul($hundredfifthF54, $nF54);
            $hundredfifthF54 = $context->builder->fmul($hundredfifthF54, $nF54);
            $hundredfifthF54 = $context->builder->fmul($hundredfifthF54, $nF54);
            $hundredfifthF54 = $context->builder->fmul($hundredfifthF54, $nF54);
            $hundredfifthF54 = $context->builder->fmul($hundredfifthF54, $nF54);
            $hundredfifthF54 = $context->builder->fmul($hundredfifthF54, $nF54);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF54
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetyfourth)');
            }
            $ninetyfourthLong = JITVariable::KIND_VARIABLE === $ninetyfourthVar->kind
                ? $context->builder->load($ninetyfourthVar->value)
                : $ninetyfourthVar->value;
            $ov55Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyfourth_ov');
            $ok55Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyfourth_ok');
            $context->builder->branchIf($ov55, $ov55Block, $ok55Block);

            $context->builder->positionAtEnd($ov55Block);
            $ninetyfourthF55 = $context->builder->load($ninetyfourthVar->longArithOverflowDoubleSlot);
            $nF55 = $context->builder->siToFp($n, $f64);
            $hundredfifthF55 = $context->builder->fmul($ninetyfourthF55, $nF55);
            $hundredfifthF55 = $context->builder->fmul($hundredfifthF55, $nF55);
            $hundredfifthF55 = $context->builder->fmul($hundredfifthF55, $nF55);
            $hundredfifthF55 = $context->builder->fmul($hundredfifthF55, $nF55);
            $hundredfifthF55 = $context->builder->fmul($hundredfifthF55, $nF55);
            $hundredfifthF55 = $context->builder->fmul($hundredfifthF55, $nF55);
            $hundredfifthF55 = $context->builder->fmul($hundredfifthF55, $nF55);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF55
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetyfifth)');
            }
            $ninetyfifthLong = JITVariable::KIND_VARIABLE === $ninetyfifthVar->kind
                ? $context->builder->load($ninetyfifthVar->value)
                : $ninetyfifthVar->value;
            $ov56Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyfifth_ov');
            $ok56Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyfifth_ok');
            $context->builder->branchIf($ov56, $ov56Block, $ok56Block);

            $context->builder->positionAtEnd($ov56Block);
            $ninetyfifthF56 = $context->builder->load($ninetyfifthVar->longArithOverflowDoubleSlot);
            $nF56 = $context->builder->siToFp($n, $f64);
            $hundredfifthF56 = $context->builder->fmul($ninetyfifthF56, $nF56);
            $hundredfifthF56 = $context->builder->fmul($hundredfifthF56, $nF56);
            $hundredfifthF56 = $context->builder->fmul($hundredfifthF56, $nF56);
            $hundredfifthF56 = $context->builder->fmul($hundredfifthF56, $nF56);
            $hundredfifthF56 = $context->builder->fmul($hundredfifthF56, $nF56);
            $hundredfifthF56 = $context->builder->fmul($hundredfifthF56, $nF56);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF56
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetysixth)');
            }
            $ninetysixthLong = JITVariable::KIND_VARIABLE === $ninetysixthVar->kind
                ? $context->builder->load($ninetysixthVar->value)
                : $ninetysixthVar->value;
            $ov57Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetysixth_ov');
            $ok57Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetysixth_ok');
            $context->builder->branchIf($ov57, $ov57Block, $ok57Block);

            $context->builder->positionAtEnd($ov57Block);
            $ninetysixthF57 = $context->builder->load($ninetysixthVar->longArithOverflowDoubleSlot);
            $nF57 = $context->builder->siToFp($n, $f64);
            $hundredfifthF57 = $context->builder->fmul($ninetysixthF57, $nF57);
            $hundredfifthF57 = $context->builder->fmul($hundredfifthF57, $nF57);
            $hundredfifthF57 = $context->builder->fmul($hundredfifthF57, $nF57);
            $hundredfifthF57 = $context->builder->fmul($hundredfifthF57, $nF57);
            $hundredfifthF57 = $context->builder->fmul($hundredfifthF57, $nF57);
            $hundredfifthF57 = $context->builder->fmul($hundredfifthF57, $nF57);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF57
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetyseventh)');
            }
            $ninetyseventhLong = JITVariable::KIND_VARIABLE === $ninetyseventhVar->kind
                ? $context->builder->load($ninetyseventhVar->value)
                : $ninetyseventhVar->value;
            $ov58Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyseventh_ov');
            $ok58Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyseventh_ok');
            $context->builder->branchIf($ov58, $ov58Block, $ok58Block);

            $context->builder->positionAtEnd($ov58Block);
            $ninetyseventhF58 = $context->builder->load($ninetyseventhVar->longArithOverflowDoubleSlot);
            $nF58 = $context->builder->siToFp($n, $f64);
            $hundredfifthF58 = $context->builder->fmul($ninetyseventhF58, $nF58);
            $hundredfifthF58 = $context->builder->fmul($hundredfifthF58, $nF58);
            $hundredfifthF58 = $context->builder->fmul($hundredfifthF58, $nF58);
            $hundredfifthF58 = $context->builder->fmul($hundredfifthF58, $nF58);
            $hundredfifthF58 = $context->builder->fmul($hundredfifthF58, $nF58);
            $hundredfifthF58 = $context->builder->fmul($hundredfifthF58, $nF58);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF58
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetyeighth)');
            }
            $ninetyeighthLong = JITVariable::KIND_VARIABLE === $ninetyeighthVar->kind
                ? $context->builder->load($ninetyeighthVar->value)
                : $ninetyeighthVar->value;
            $ov59Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyeighth_ov');
            $ok59Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyeighth_ok');
            $context->builder->branchIf($ov59, $ov59Block, $ok59Block);

            $context->builder->positionAtEnd($ov59Block);
            $ninetyeighthF59 = $context->builder->load($ninetyeighthVar->longArithOverflowDoubleSlot);
            $nF59 = $context->builder->siToFp($n, $f64);
            $hundredfifthF59 = $context->builder->fmul($ninetyeighthF59, $nF59);
            $hundredfifthF59 = $context->builder->fmul($hundredfifthF59, $nF59);
            $hundredfifthF59 = $context->builder->fmul($hundredfifthF59, $nF59);
            $hundredfifthF59 = $context->builder->fmul($hundredfifthF59, $nF59);
            $hundredfifthF59 = $context->builder->fmul($hundredfifthF59, $nF59);
            $hundredfifthF59 = $context->builder->fmul($hundredfifthF59, $nF59);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF59
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (ninetyninth)');
            }
            $ninetyninthLong = JITVariable::KIND_VARIABLE === $ninetyninthVar->kind
                ? $context->builder->load($ninetyninthVar->value)
                : $ninetyninthVar->value;
            $ov60Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyninth_ov');
            $ok60Block = BasicBlockHelper::append($context, 'pow_hundredfifth_ninetyninth_ok');
            $context->builder->branchIf($ov60, $ov60Block, $ok60Block);

            $context->builder->positionAtEnd($ov60Block);
            $ninetyninthF60 = $context->builder->load($ninetyninthVar->longArithOverflowDoubleSlot);
            $nF60 = $context->builder->siToFp($n, $f64);
            $hundredfifthF60 = $context->builder->fmul($ninetyninthF60, $nF60);
            $hundredfifthF60 = $context->builder->fmul($hundredfifthF60, $nF60);
            $hundredfifthF60 = $context->builder->fmul($hundredfifthF60, $nF60);
            $hundredfifthF60 = $context->builder->fmul($hundredfifthF60, $nF60);
            $hundredfifthF60 = $context->builder->fmul($hundredfifthF60, $nF60);
            $hundredfifthF60 = $context->builder->fmul($hundredfifthF60, $nF60);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF60
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
                throw new \LogicException('pow() **105 expected smul overflow metadata (hundredth)');
            }
            $hundredthLong = JITVariable::KIND_VARIABLE === $hundredthVar->kind
                ? $context->builder->load($hundredthVar->value)
                : $hundredthVar->value;
            $ov61Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredth_ov');
            $ok61Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredth_ok');
            $context->builder->branchIf($ov61, $ov61Block, $ok61Block);

            $context->builder->positionAtEnd($ov61Block);
            $hundredthF61 = $context->builder->load($hundredthVar->longArithOverflowDoubleSlot);
            $nF61 = $context->builder->siToFp($n, $f64);
            $hundredfifthF61 = $context->builder->fmul($hundredthF61, $nF61);
            $hundredfifthF61 = $context->builder->fmul($hundredfifthF61, $nF61);
            $hundredfifthF61 = $context->builder->fmul($hundredfifthF61, $nF61);
            $hundredfifthF61 = $context->builder->fmul($hundredfifthF61, $nF61);
            $hundredfifthF61 = $context->builder->fmul($hundredfifthF61, $nF61);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF61
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok61Block);
            $hundredfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $hundredthLong,
                $n
            );
            $ov62 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredfirstVar->longArithOverflowFlag);
            if (null === $ov62 || null === $hundredfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **105 expected smul overflow metadata (hundredfirst)');
            }
            $hundredfirstLong = JITVariable::KIND_VARIABLE === $hundredfirstVar->kind
                ? $context->builder->load($hundredfirstVar->value)
                : $hundredfirstVar->value;
            $ov62Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredfirst_ov');
            $ok62Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredfirst_ok');
            $context->builder->branchIf($ov62, $ov62Block, $ok62Block);

            $context->builder->positionAtEnd($ov62Block);
            $hundredfirstF62 = $context->builder->load($hundredfirstVar->longArithOverflowDoubleSlot);
            $nF62 = $context->builder->siToFp($n, $f64);
            $hundredfifthF62 = $context->builder->fmul($hundredfirstF62, $nF62);
            $hundredfifthF62 = $context->builder->fmul($hundredfifthF62, $nF62);
            $hundredfifthF62 = $context->builder->fmul($hundredfifthF62, $nF62);
            $hundredfifthF62 = $context->builder->fmul($hundredfifthF62, $nF62);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF62
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok62Block);
            $hundredsecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $hundredfirstLong,
                $n
            );
            $ov63 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredsecondVar->longArithOverflowFlag);
            if (null === $ov63 || null === $hundredsecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **105 expected smul overflow metadata (hundredsecond)');
            }
            $hundredsecondLong = JITVariable::KIND_VARIABLE === $hundredsecondVar->kind
                ? $context->builder->load($hundredsecondVar->value)
                : $hundredsecondVar->value;
            $ov63Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredsecond_ov');
            $ok63Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredsecond_ok');
            $context->builder->branchIf($ov63, $ov63Block, $ok63Block);

            $context->builder->positionAtEnd($ov63Block);
            $hundredsecondF63 = $context->builder->load($hundredsecondVar->longArithOverflowDoubleSlot);
            $nF63 = $context->builder->siToFp($n, $f64);
            $hundredfifthF63 = $context->builder->fmul($hundredsecondF63, $nF63);
            $hundredfifthF63 = $context->builder->fmul($hundredfifthF63, $nF63);
            $hundredfifthF63 = $context->builder->fmul($hundredfifthF63, $nF63);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF63
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok63Block);
            $hundredthirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $hundredsecondLong,
                $n
            );
            $ov64 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredthirdVar->longArithOverflowFlag);
            if (null === $ov64 || null === $hundredthirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **105 expected smul overflow metadata (hundredthird)');
            }
            $hundredthirdLong = JITVariable::KIND_VARIABLE === $hundredthirdVar->kind
                ? $context->builder->load($hundredthirdVar->value)
                : $hundredthirdVar->value;
            $ov64Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredthird_ov');
            $ok64Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredthird_ok');
            $context->builder->branchIf($ov64, $ov64Block, $ok64Block);

            $context->builder->positionAtEnd($ov64Block);
            $hundredthirdF64 = $context->builder->load($hundredthirdVar->longArithOverflowDoubleSlot);
            $nF64 = $context->builder->siToFp($n, $f64);
            $hundredfifthF64 = $context->builder->fmul($hundredthirdF64, $nF64);
            $hundredfifthF64 = $context->builder->fmul($hundredfifthF64, $nF64);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF64
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok64Block);
            $hundredfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $hundredthirdLong,
                $n
            );
            $ov65 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredfourthVar->longArithOverflowFlag);
            if (null === $ov65 || null === $hundredfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **105 expected smul overflow metadata (hundredfourth)');
            }
            $hundredfourthLong = JITVariable::KIND_VARIABLE === $hundredfourthVar->kind
                ? $context->builder->load($hundredfourthVar->value)
                : $hundredfourthVar->value;
            $ov65Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredfourth_ov');
            $ok65Block = BasicBlockHelper::append($context, 'pow_hundredfifth_hundredfourth_ok');
            $context->builder->branchIf($ov65, $ov65Block, $ok65Block);

            $context->builder->positionAtEnd($ov65Block);
            $hundredfourthF65 = $context->builder->load($hundredfourthVar->longArithOverflowDoubleSlot);
            $nF65 = $context->builder->siToFp($n, $f64);
            $hundredfifthF65 = $context->builder->fmul($hundredfourthF65, $nF65);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredfifthF65
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok65Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $hundredfourthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('hundredsixth' === $expFold) {
            // n^105 = hundredfourth*n; overflow arms +1 ×nF vs **104.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **106.
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_hundredsixth_done');
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
            $hundredsixthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $hundredsixthFEighth = $context->builder->fmul($hundredsixthFSq, $sqF);
            $hundredsixthF = $context->builder->fmul($hundredsixthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
                $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
                    $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
                        $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
                        $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
                        $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
                        $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
                        $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
                        $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
                        $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);

            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $hundredsixthF = $context->builder->fmul($hundredsixthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_hundredsixth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_hundredsixth_cu_ok');
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
            $hundredsixthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $hundredsixthF2Eighth = $context->builder->fmul($hundredsixthF2Sq, $sqF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
                $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
                    $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
                        $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
                        $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
                        $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
                        $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
                        $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
                        $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
                        $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);

            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF2);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF);
            $hundredsixthF2 = $context->builder->fmul($hundredsixthF2, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF2
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $hundredsixthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $hundredsixthF3Eighth = $context->builder->fmul($hundredsixthF3Sq, $sqF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
                $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
                    $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
                        $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
                        $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
                        $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
                        $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
                        $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
                        $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
                        $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);

            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF3);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF);
            $hundredsixthF3 = $context->builder->fmul($hundredsixthF3, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF3
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_hundredsixth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_hundredsixth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $hundredsixthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $hundredsixthF4Eighth = $context->builder->fmul($hundredsixthF4Sq, $sqF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
                $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
                    $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
                        $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
                        $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
                        $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
                        $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
                        $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
                        $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
                        $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);

            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF4);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF);
            $hundredsixthF4 = $context->builder->fmul($hundredsixthF4, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF4
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_hundredsixth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_hundredsixth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $hundredsixthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $hundredsixthF5Eighth = $context->builder->fmul($hundredsixthF5Sq, $sqF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
                $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
                    $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
                        $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
                        $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
                        $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
                        $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
                        $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
                        $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
                        $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);

            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF5);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF);
            $hundredsixthF5 = $context->builder->fmul($hundredsixthF5, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF5
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $hundredsixthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $hundredsixthF6Eighth = $context->builder->fmul($hundredsixthF6Sq, $sqF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
                $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
                    $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
                        $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
                        $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
                        $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
                        $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
                        $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
                        $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
                        $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);

            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF6);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF);
            $hundredsixthF6 = $context->builder->fmul($hundredsixthF6, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF6
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $hundredsixthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $hundredsixthF7Eighth = $context->builder->fmul($hundredsixthF7Sq, $sqF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
                $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
                    $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
                        $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
                        $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
                        $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
                        $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
                        $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
                        $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
                        $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);

            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF7);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF);
            $hundredsixthF7 = $context->builder->fmul($hundredsixthF7, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF7
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $hundredsixthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $hundredsixthF8Eighth = $context->builder->fmul($hundredsixthF8Sq, $sqF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
                $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
                    $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
                        $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
                        $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
                        $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
                        $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
                        $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
                        $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
                        $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);

            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF8);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF);
            $hundredsixthF8 = $context->builder->fmul($hundredsixthF8, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF8
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $hundredsixthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
                $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
                    $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
                        $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
                        $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
                        $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
                        $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
                        $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
                        $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
                        $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);

            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF9);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF);
            $hundredsixthF9 = $context->builder->fmul($hundredsixthF9, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF9
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $hundredsixthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
                $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
                    $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
                        $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
                        $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
                        $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
                        $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
                        $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
                        $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
                        $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);

            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF10);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF);
            $hundredsixthF10 = $context->builder->fmul($hundredsixthF10, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF10
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $hundredsixthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
                $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
                    $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
                        $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
                        $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
                        $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
                        $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
                        $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
                        $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
                        $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);

            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF11);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF);
            $hundredsixthF11 = $context->builder->fmul($hundredsixthF11, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF11
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $hundredsixthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
                $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
                    $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
                        $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
                        $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
                        $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
                        $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
                        $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
                        $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
                        $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);

            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF12);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF);
            $hundredsixthF12 = $context->builder->fmul($hundredsixthF12, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF12
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $hundredsixthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
                $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
                    $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
                        $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
                        $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
                        $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
                        $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
                        $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
                        $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
                        $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);

            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF13);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF);
            $hundredsixthF13 = $context->builder->fmul($hundredsixthF13, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF13
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $hundredsixthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
                $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
                    $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
                        $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
                        $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
                        $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
                        $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
                        $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
                        $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
                        $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);

            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF14);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF);
            $hundredsixthF14 = $context->builder->fmul($hundredsixthF14, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF14
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $hundredsixthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
                $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
                    $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
                        $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
                        $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
                        $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
                        $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
                        $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
                        $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
                        $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);

            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF15);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF);
            $hundredsixthF15 = $context->builder->fmul($hundredsixthF15, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF15
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $hundredsixthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
                $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
                    $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
                        $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
                        $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
                        $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
                        $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
                        $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
                        $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
                        $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);

            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF16);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF);
            $hundredsixthF16 = $context->builder->fmul($hundredsixthF16, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF16
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $hundredsixthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
                $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
                    $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
                        $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
                        $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
                        $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
                        $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
                        $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
                        $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
                        $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);

            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF17);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF);
            $hundredsixthF17 = $context->builder->fmul($hundredsixthF17, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF17
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $hundredsixthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
                $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
                    $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
                        $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
                        $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
                        $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
                        $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
                        $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
                        $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
                        $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);

            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF18);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF);
            $hundredsixthF18 = $context->builder->fmul($hundredsixthF18, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF18
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $hundredsixthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
                $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
                    $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
                        $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
                        $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
                        $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
                        $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
                        $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
                        $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
                        $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);

            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF19);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF);
            $hundredsixthF19 = $context->builder->fmul($hundredsixthF19, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF19
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_hundredsixth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $hundredsixthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
                    $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
                        $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
                        $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
                        $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
                        $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
                        $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
                        $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
                        $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);

            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF20);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF);
            $hundredsixthF20 = $context->builder->fmul($hundredsixthF20, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF20
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $hundredsixthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
                    $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
                    $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
                    $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
                    $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
                    $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
                    $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
                    $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);

            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF21);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF);
            $hundredsixthF21 = $context->builder->fmul($hundredsixthF21, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF21
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $hundredsixthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
                $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
                $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
                $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
                $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
                $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
                $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);

            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF22);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF);
            $hundredsixthF22 = $context->builder->fmul($hundredsixthF22, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF22
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $hundredsixthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);

            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF23);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF);
            $hundredsixthF23 = $context->builder->fmul($hundredsixthF23, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF23
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $hundredsixthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);

            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF24);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF);
            $hundredsixthF24 = $context->builder->fmul($hundredsixthF24, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF24
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $hundredsixthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);

            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF25);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF);
            $hundredsixthF25 = $context->builder->fmul($hundredsixthF25, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF25
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $hundredsixthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);

            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF26);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF);
            $hundredsixthF26 = $context->builder->fmul($hundredsixthF26, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF26
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $hundredsixthF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);

            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF27);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF);
            $hundredsixthF27 = $context->builder->fmul($hundredsixthF27, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF27
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $hundredsixthF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);

            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF28);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF);
            $hundredsixthF28 = $context->builder->fmul($hundredsixthF28, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF28
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $hundredsixthF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);

            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF29);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF);
            $hundredsixthF29 = $context->builder->fmul($hundredsixthF29, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF29
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_hundredsixth_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $hundredsixthF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF30);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF);
            $hundredsixthF30 = $context->builder->fmul($hundredsixthF30, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF30
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $hundredsixthF31 = $context->builder->fmul($seventiethF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF31);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF);
            $hundredsixthF31 = $context->builder->fmul($hundredsixthF31, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF31
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $hundredsixthF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF32);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF);
            $hundredsixthF32 = $context->builder->fmul($hundredsixthF32, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF32
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $hundredsixthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF33);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF);
            $hundredsixthF33 = $context->builder->fmul($hundredsixthF33, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF33
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $hundredsixthF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF34);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF);
            $hundredsixthF34 = $context->builder->fmul($hundredsixthF34, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF34
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $hundredsixthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF35);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF);
            $hundredsixthF35 = $context->builder->fmul($hundredsixthF35, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF35
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $hundredsixthF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF36);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF);
            $hundredsixthF36 = $context->builder->fmul($hundredsixthF36, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF36
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $hundredsixthF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF37);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF);
            $hundredsixthF37 = $context->builder->fmul($hundredsixthF37, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF37
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $hundredsixthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF38);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF);
            $hundredsixthF38 = $context->builder->fmul($hundredsixthF38, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF38
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $hundredsixthF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $hundredsixthF39 = $context->builder->fmul($hundredsixthF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF39
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_hundredsixth_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $hundredsixthF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $hundredsixthF40 = $context->builder->fmul($hundredsixthF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF40
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $hundredsixthF41 = $context->builder->fmul($eightiethF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $hundredsixthF41 = $context->builder->fmul($hundredsixthF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF41
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $hundredsixthF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $hundredsixthF42 = $context->builder->fmul($hundredsixthF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF42
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $hundredsixthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $hundredsixthF43 = $context->builder->fmul($hundredsixthF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF43
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $hundredsixthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $hundredsixthF44 = $context->builder->fmul($hundredsixthF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF44
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $hundredsixthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $hundredsixthF45 = $context->builder->fmul($hundredsixthF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF45
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $hundredsixthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $hundredsixthF46 = $context->builder->fmul($hundredsixthF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF46
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $hundredsixthF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $hundredsixthF47 = $context->builder->fmul($hundredsixthF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF47
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $hundredsixthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $hundredsixthF48 = $context->builder->fmul($hundredsixthF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF48
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $hundredsixthF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $hundredsixthF49 = $context->builder->fmul($hundredsixthF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF49
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (eightyninth)');
            }
            $eightyninthLong = JITVariable::KIND_VARIABLE === $eightyninthVar->kind
                ? $context->builder->load($eightyninthVar->value)
                : $eightyninthVar->value;
            $ov50Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyninth_ov');
            $ok50Block = BasicBlockHelper::append($context, 'pow_hundredsixth_eightyninth_ok');
            $context->builder->branchIf($ov50, $ov50Block, $ok50Block);

            $context->builder->positionAtEnd($ov50Block);
            $eightyninthF50 = $context->builder->load($eightyninthVar->longArithOverflowDoubleSlot);
            $nF50 = $context->builder->siToFp($n, $f64);
            $hundredsixthF50 = $context->builder->fmul($eightyninthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $hundredsixthF50 = $context->builder->fmul($hundredsixthF50, $nF50);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF50
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetieth)');
            }
            $ninetiethLong = JITVariable::KIND_VARIABLE === $ninetiethVar->kind
                ? $context->builder->load($ninetiethVar->value)
                : $ninetiethVar->value;
            $ov51Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetieth_ov');
            $ok51Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetieth_ok');
            $context->builder->branchIf($ov51, $ov51Block, $ok51Block);

            $context->builder->positionAtEnd($ov51Block);
            $ninetiethF51 = $context->builder->load($ninetiethVar->longArithOverflowDoubleSlot);
            $nF51 = $context->builder->siToFp($n, $f64);
            $hundredsixthF51 = $context->builder->fmul($ninetiethF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $hundredsixthF51 = $context->builder->fmul($hundredsixthF51, $nF51);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF51
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetyfirst)');
            }
            $ninetyfirstLong = JITVariable::KIND_VARIABLE === $ninetyfirstVar->kind
                ? $context->builder->load($ninetyfirstVar->value)
                : $ninetyfirstVar->value;
            $ov52Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyfirst_ov');
            $ok52Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyfirst_ok');
            $context->builder->branchIf($ov52, $ov52Block, $ok52Block);

            $context->builder->positionAtEnd($ov52Block);
            $ninetyfirstF52 = $context->builder->load($ninetyfirstVar->longArithOverflowDoubleSlot);
            $nF52 = $context->builder->siToFp($n, $f64);
            $hundredsixthF52 = $context->builder->fmul($ninetyfirstF52, $nF52);
            $hundredsixthF52 = $context->builder->fmul($hundredsixthF52, $nF52);
            $hundredsixthF52 = $context->builder->fmul($hundredsixthF52, $nF52);
            $hundredsixthF52 = $context->builder->fmul($hundredsixthF52, $nF52);
            $hundredsixthF52 = $context->builder->fmul($hundredsixthF52, $nF52);
            $hundredsixthF52 = $context->builder->fmul($hundredsixthF52, $nF52);
            $hundredsixthF52 = $context->builder->fmul($hundredsixthF52, $nF52);
            $hundredsixthF52 = $context->builder->fmul($hundredsixthF52, $nF52);
            $hundredsixthF52 = $context->builder->fmul($hundredsixthF52, $nF52);
            $hundredsixthF52 = $context->builder->fmul($hundredsixthF52, $nF52);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF52
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetysecond)');
            }
            $ninetysecondLong = JITVariable::KIND_VARIABLE === $ninetysecondVar->kind
                ? $context->builder->load($ninetysecondVar->value)
                : $ninetysecondVar->value;
            $ov53Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetysecond_ov');
            $ok53Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetysecond_ok');
            $context->builder->branchIf($ov53, $ov53Block, $ok53Block);

            $context->builder->positionAtEnd($ov53Block);
            $ninetysecondF53 = $context->builder->load($ninetysecondVar->longArithOverflowDoubleSlot);
            $nF53 = $context->builder->siToFp($n, $f64);
            $hundredsixthF53 = $context->builder->fmul($ninetysecondF53, $nF53);
            $hundredsixthF53 = $context->builder->fmul($hundredsixthF53, $nF53);
            $hundredsixthF53 = $context->builder->fmul($hundredsixthF53, $nF53);
            $hundredsixthF53 = $context->builder->fmul($hundredsixthF53, $nF53);
            $hundredsixthF53 = $context->builder->fmul($hundredsixthF53, $nF53);
            $hundredsixthF53 = $context->builder->fmul($hundredsixthF53, $nF53);
            $hundredsixthF53 = $context->builder->fmul($hundredsixthF53, $nF53);
            $hundredsixthF53 = $context->builder->fmul($hundredsixthF53, $nF53);
            $hundredsixthF53 = $context->builder->fmul($hundredsixthF53, $nF53);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF53
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetythird)');
            }
            $ninetythirdLong = JITVariable::KIND_VARIABLE === $ninetythirdVar->kind
                ? $context->builder->load($ninetythirdVar->value)
                : $ninetythirdVar->value;
            $ov54Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetythird_ov');
            $ok54Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetythird_ok');
            $context->builder->branchIf($ov54, $ov54Block, $ok54Block);

            $context->builder->positionAtEnd($ov54Block);
            $ninetythirdF54 = $context->builder->load($ninetythirdVar->longArithOverflowDoubleSlot);
            $nF54 = $context->builder->siToFp($n, $f64);
            $hundredsixthF54 = $context->builder->fmul($ninetythirdF54, $nF54);
            $hundredsixthF54 = $context->builder->fmul($hundredsixthF54, $nF54);
            $hundredsixthF54 = $context->builder->fmul($hundredsixthF54, $nF54);
            $hundredsixthF54 = $context->builder->fmul($hundredsixthF54, $nF54);
            $hundredsixthF54 = $context->builder->fmul($hundredsixthF54, $nF54);
            $hundredsixthF54 = $context->builder->fmul($hundredsixthF54, $nF54);
            $hundredsixthF54 = $context->builder->fmul($hundredsixthF54, $nF54);
            $hundredsixthF54 = $context->builder->fmul($hundredsixthF54, $nF54);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF54
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetyfourth)');
            }
            $ninetyfourthLong = JITVariable::KIND_VARIABLE === $ninetyfourthVar->kind
                ? $context->builder->load($ninetyfourthVar->value)
                : $ninetyfourthVar->value;
            $ov55Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyfourth_ov');
            $ok55Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyfourth_ok');
            $context->builder->branchIf($ov55, $ov55Block, $ok55Block);

            $context->builder->positionAtEnd($ov55Block);
            $ninetyfourthF55 = $context->builder->load($ninetyfourthVar->longArithOverflowDoubleSlot);
            $nF55 = $context->builder->siToFp($n, $f64);
            $hundredsixthF55 = $context->builder->fmul($ninetyfourthF55, $nF55);
            $hundredsixthF55 = $context->builder->fmul($hundredsixthF55, $nF55);
            $hundredsixthF55 = $context->builder->fmul($hundredsixthF55, $nF55);
            $hundredsixthF55 = $context->builder->fmul($hundredsixthF55, $nF55);
            $hundredsixthF55 = $context->builder->fmul($hundredsixthF55, $nF55);
            $hundredsixthF55 = $context->builder->fmul($hundredsixthF55, $nF55);
            $hundredsixthF55 = $context->builder->fmul($hundredsixthF55, $nF55);
            $hundredsixthF55 = $context->builder->fmul($hundredsixthF55, $nF55);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF55
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetyfifth)');
            }
            $ninetyfifthLong = JITVariable::KIND_VARIABLE === $ninetyfifthVar->kind
                ? $context->builder->load($ninetyfifthVar->value)
                : $ninetyfifthVar->value;
            $ov56Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyfifth_ov');
            $ok56Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyfifth_ok');
            $context->builder->branchIf($ov56, $ov56Block, $ok56Block);

            $context->builder->positionAtEnd($ov56Block);
            $ninetyfifthF56 = $context->builder->load($ninetyfifthVar->longArithOverflowDoubleSlot);
            $nF56 = $context->builder->siToFp($n, $f64);
            $hundredsixthF56 = $context->builder->fmul($ninetyfifthF56, $nF56);
            $hundredsixthF56 = $context->builder->fmul($hundredsixthF56, $nF56);
            $hundredsixthF56 = $context->builder->fmul($hundredsixthF56, $nF56);
            $hundredsixthF56 = $context->builder->fmul($hundredsixthF56, $nF56);
            $hundredsixthF56 = $context->builder->fmul($hundredsixthF56, $nF56);
            $hundredsixthF56 = $context->builder->fmul($hundredsixthF56, $nF56);
            $hundredsixthF56 = $context->builder->fmul($hundredsixthF56, $nF56);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF56
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetysixth)');
            }
            $ninetysixthLong = JITVariable::KIND_VARIABLE === $ninetysixthVar->kind
                ? $context->builder->load($ninetysixthVar->value)
                : $ninetysixthVar->value;
            $ov57Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetysixth_ov');
            $ok57Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetysixth_ok');
            $context->builder->branchIf($ov57, $ov57Block, $ok57Block);

            $context->builder->positionAtEnd($ov57Block);
            $ninetysixthF57 = $context->builder->load($ninetysixthVar->longArithOverflowDoubleSlot);
            $nF57 = $context->builder->siToFp($n, $f64);
            $hundredsixthF57 = $context->builder->fmul($ninetysixthF57, $nF57);
            $hundredsixthF57 = $context->builder->fmul($hundredsixthF57, $nF57);
            $hundredsixthF57 = $context->builder->fmul($hundredsixthF57, $nF57);
            $hundredsixthF57 = $context->builder->fmul($hundredsixthF57, $nF57);
            $hundredsixthF57 = $context->builder->fmul($hundredsixthF57, $nF57);
            $hundredsixthF57 = $context->builder->fmul($hundredsixthF57, $nF57);
            $hundredsixthF57 = $context->builder->fmul($hundredsixthF57, $nF57);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF57
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetyseventh)');
            }
            $ninetyseventhLong = JITVariable::KIND_VARIABLE === $ninetyseventhVar->kind
                ? $context->builder->load($ninetyseventhVar->value)
                : $ninetyseventhVar->value;
            $ov58Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyseventh_ov');
            $ok58Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyseventh_ok');
            $context->builder->branchIf($ov58, $ov58Block, $ok58Block);

            $context->builder->positionAtEnd($ov58Block);
            $ninetyseventhF58 = $context->builder->load($ninetyseventhVar->longArithOverflowDoubleSlot);
            $nF58 = $context->builder->siToFp($n, $f64);
            $hundredsixthF58 = $context->builder->fmul($ninetyseventhF58, $nF58);
            $hundredsixthF58 = $context->builder->fmul($hundredsixthF58, $nF58);
            $hundredsixthF58 = $context->builder->fmul($hundredsixthF58, $nF58);
            $hundredsixthF58 = $context->builder->fmul($hundredsixthF58, $nF58);
            $hundredsixthF58 = $context->builder->fmul($hundredsixthF58, $nF58);
            $hundredsixthF58 = $context->builder->fmul($hundredsixthF58, $nF58);
            $hundredsixthF58 = $context->builder->fmul($hundredsixthF58, $nF58);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF58
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetyeighth)');
            }
            $ninetyeighthLong = JITVariable::KIND_VARIABLE === $ninetyeighthVar->kind
                ? $context->builder->load($ninetyeighthVar->value)
                : $ninetyeighthVar->value;
            $ov59Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyeighth_ov');
            $ok59Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyeighth_ok');
            $context->builder->branchIf($ov59, $ov59Block, $ok59Block);

            $context->builder->positionAtEnd($ov59Block);
            $ninetyeighthF59 = $context->builder->load($ninetyeighthVar->longArithOverflowDoubleSlot);
            $nF59 = $context->builder->siToFp($n, $f64);
            $hundredsixthF59 = $context->builder->fmul($ninetyeighthF59, $nF59);
            $hundredsixthF59 = $context->builder->fmul($hundredsixthF59, $nF59);
            $hundredsixthF59 = $context->builder->fmul($hundredsixthF59, $nF59);
            $hundredsixthF59 = $context->builder->fmul($hundredsixthF59, $nF59);
            $hundredsixthF59 = $context->builder->fmul($hundredsixthF59, $nF59);
            $hundredsixthF59 = $context->builder->fmul($hundredsixthF59, $nF59);
            $hundredsixthF59 = $context->builder->fmul($hundredsixthF59, $nF59);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF59
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (ninetyninth)');
            }
            $ninetyninthLong = JITVariable::KIND_VARIABLE === $ninetyninthVar->kind
                ? $context->builder->load($ninetyninthVar->value)
                : $ninetyninthVar->value;
            $ov60Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyninth_ov');
            $ok60Block = BasicBlockHelper::append($context, 'pow_hundredsixth_ninetyninth_ok');
            $context->builder->branchIf($ov60, $ov60Block, $ok60Block);

            $context->builder->positionAtEnd($ov60Block);
            $ninetyninthF60 = $context->builder->load($ninetyninthVar->longArithOverflowDoubleSlot);
            $nF60 = $context->builder->siToFp($n, $f64);
            $hundredsixthF60 = $context->builder->fmul($ninetyninthF60, $nF60);
            $hundredsixthF60 = $context->builder->fmul($hundredsixthF60, $nF60);
            $hundredsixthF60 = $context->builder->fmul($hundredsixthF60, $nF60);
            $hundredsixthF60 = $context->builder->fmul($hundredsixthF60, $nF60);
            $hundredsixthF60 = $context->builder->fmul($hundredsixthF60, $nF60);
            $hundredsixthF60 = $context->builder->fmul($hundredsixthF60, $nF60);
            $hundredsixthF60 = $context->builder->fmul($hundredsixthF60, $nF60);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF60
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
                throw new \LogicException('pow() **106 expected smul overflow metadata (hundredth)');
            }
            $hundredthLong = JITVariable::KIND_VARIABLE === $hundredthVar->kind
                ? $context->builder->load($hundredthVar->value)
                : $hundredthVar->value;
            $ov61Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredth_ov');
            $ok61Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredth_ok');
            $context->builder->branchIf($ov61, $ov61Block, $ok61Block);

            $context->builder->positionAtEnd($ov61Block);
            $hundredthF61 = $context->builder->load($hundredthVar->longArithOverflowDoubleSlot);
            $nF61 = $context->builder->siToFp($n, $f64);
            $hundredsixthF61 = $context->builder->fmul($hundredthF61, $nF61);
            $hundredsixthF61 = $context->builder->fmul($hundredsixthF61, $nF61);
            $hundredsixthF61 = $context->builder->fmul($hundredsixthF61, $nF61);
            $hundredsixthF61 = $context->builder->fmul($hundredsixthF61, $nF61);
            $hundredsixthF61 = $context->builder->fmul($hundredsixthF61, $nF61);
            $hundredsixthF61 = $context->builder->fmul($hundredsixthF61, $nF61);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF61
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok61Block);
            $hundredfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $hundredthLong,
                $n
            );
            $ov62 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredfirstVar->longArithOverflowFlag);
            if (null === $ov62 || null === $hundredfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **106 expected smul overflow metadata (hundredfirst)');
            }
            $hundredfirstLong = JITVariable::KIND_VARIABLE === $hundredfirstVar->kind
                ? $context->builder->load($hundredfirstVar->value)
                : $hundredfirstVar->value;
            $ov62Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredfirst_ov');
            $ok62Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredfirst_ok');
            $context->builder->branchIf($ov62, $ov62Block, $ok62Block);

            $context->builder->positionAtEnd($ov62Block);
            $hundredfirstF62 = $context->builder->load($hundredfirstVar->longArithOverflowDoubleSlot);
            $nF62 = $context->builder->siToFp($n, $f64);
            $hundredsixthF62 = $context->builder->fmul($hundredfirstF62, $nF62);
            $hundredsixthF62 = $context->builder->fmul($hundredsixthF62, $nF62);
            $hundredsixthF62 = $context->builder->fmul($hundredsixthF62, $nF62);
            $hundredsixthF62 = $context->builder->fmul($hundredsixthF62, $nF62);
            $hundredsixthF62 = $context->builder->fmul($hundredsixthF62, $nF62);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF62
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok62Block);
            $hundredsecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $hundredfirstLong,
                $n
            );
            $ov63 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredsecondVar->longArithOverflowFlag);
            if (null === $ov63 || null === $hundredsecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **106 expected smul overflow metadata (hundredsecond)');
            }
            $hundredsecondLong = JITVariable::KIND_VARIABLE === $hundredsecondVar->kind
                ? $context->builder->load($hundredsecondVar->value)
                : $hundredsecondVar->value;
            $ov63Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredsecond_ov');
            $ok63Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredsecond_ok');
            $context->builder->branchIf($ov63, $ov63Block, $ok63Block);

            $context->builder->positionAtEnd($ov63Block);
            $hundredsecondF63 = $context->builder->load($hundredsecondVar->longArithOverflowDoubleSlot);
            $nF63 = $context->builder->siToFp($n, $f64);
            $hundredsixthF63 = $context->builder->fmul($hundredsecondF63, $nF63);
            $hundredsixthF63 = $context->builder->fmul($hundredsixthF63, $nF63);
            $hundredsixthF63 = $context->builder->fmul($hundredsixthF63, $nF63);
            $hundredsixthF63 = $context->builder->fmul($hundredsixthF63, $nF63);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF63
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok63Block);
            $hundredthirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $hundredsecondLong,
                $n
            );
            $ov64 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredthirdVar->longArithOverflowFlag);
            if (null === $ov64 || null === $hundredthirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **106 expected smul overflow metadata (hundredthird)');
            }
            $hundredthirdLong = JITVariable::KIND_VARIABLE === $hundredthirdVar->kind
                ? $context->builder->load($hundredthirdVar->value)
                : $hundredthirdVar->value;
            $ov64Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredthird_ov');
            $ok64Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredthird_ok');
            $context->builder->branchIf($ov64, $ov64Block, $ok64Block);

            $context->builder->positionAtEnd($ov64Block);
            $hundredthirdF64 = $context->builder->load($hundredthirdVar->longArithOverflowDoubleSlot);
            $nF64 = $context->builder->siToFp($n, $f64);
            $hundredsixthF64 = $context->builder->fmul($hundredthirdF64, $nF64);
            $hundredsixthF64 = $context->builder->fmul($hundredsixthF64, $nF64);
            $hundredsixthF64 = $context->builder->fmul($hundredsixthF64, $nF64);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF64
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok64Block);
            $hundredfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $hundredthirdLong,
                $n
            );
            $ov65 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredfourthVar->longArithOverflowFlag);
            if (null === $ov65 || null === $hundredfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **106 expected smul overflow metadata (hundredfourth)');
            }
            $hundredfourthLong = JITVariable::KIND_VARIABLE === $hundredfourthVar->kind
                ? $context->builder->load($hundredfourthVar->value)
                : $hundredfourthVar->value;
            $ov65Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredfourth_ov');
            $ok65Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredfourth_ok');
            $context->builder->branchIf($ov65, $ov65Block, $ok65Block);

            $context->builder->positionAtEnd($ov65Block);
            $hundredfourthF65 = $context->builder->load($hundredfourthVar->longArithOverflowDoubleSlot);
            $nF65 = $context->builder->siToFp($n, $f64);
            $hundredsixthF65 = $context->builder->fmul($hundredfourthF65, $nF65);
            $hundredsixthF65 = $context->builder->fmul($hundredsixthF65, $nF65);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF65
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok65Block);
            $hundredfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $hundredfourthLong,
                $n
            );
            $ov66 = JitLongArithOverflow::loadOverflowFlagI1($context, $hundredfifthVar->longArithOverflowFlag);
            if (null === $ov66 || null === $hundredfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **106 expected smul overflow metadata (hundredfifth)');
            }
            $hundredfifthLong = JITVariable::KIND_VARIABLE === $hundredfifthVar->kind
                ? $context->builder->load($hundredfifthVar->value)
                : $hundredfifthVar->value;
            $ov66Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredfifth_ov');
            $ok66Block = BasicBlockHelper::append($context, 'pow_hundredsixth_hundredfifth_ok');
            $context->builder->branchIf($ov66, $ov66Block, $ok66Block);

            $context->builder->positionAtEnd($ov66Block);
            $hundredfifthF66 = $context->builder->load($hundredfifthVar->longArithOverflowDoubleSlot);
            $nF66 = $context->builder->siToFp($n, $f64);
            $hundredsixthF66 = $context->builder->fmul($hundredfifthF66, $nF66);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $hundredsixthF66
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok66Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $hundredfifthLong,
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
