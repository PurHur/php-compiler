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
 * Compile-time exponent integer {@code pow}/{@code **} chained-smul emit
 * for eightieth … eightyfifth (#36387 / #36386).
 *
 * Extracted from {@see JitPowIntegerEmitExponents80to91} so gen-0 spine gets
 * another TU (80to91 retains eightysixth … ninetyfirst).
 *
 * No new C ABI. php-src: Zend/zend_operators.c {@code pow_function} /
 * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
 * {@code PHP_FUNCTION(pow)}.
 */
final class JitPowIntegerEmitExponents80to85
{
    /**
     * @return bool true when {@code $expFold} was an 80–85 exponent and emit ran
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
        static $exponents = [
            'eightieth' => true,
            'eightyfirst' => true,
            'eightysecond' => true,
            'eightythird' => true,
            'eightyfourth' => true,
            'eightyfifth' => true,
        ];
        if (!isset($exponents[$expFold])) {
            return false;
        }

        if ('eightieth' === $expFold) {
            // n^80 = seventyninth*n; overflow arms +1 ×nF vs **79.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **79.
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eightieth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eightieth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eightieth_done');
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
            $eightiethFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $eightiethFEighth = $context->builder->fmul($eightiethFSq, $sqF);
            $eightiethF = $context->builder->fmul($eightiethFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
                $eightiethF = $context->builder->fmul($eightiethF, $nF);
                    $eightiethF = $context->builder->fmul($eightiethF, $nF);
                        $eightiethF = $context->builder->fmul($eightiethF, $nF);
                        $eightiethF = $context->builder->fmul($eightiethF, $nF);
                        $eightiethF = $context->builder->fmul($eightiethF, $nF);
                        $eightiethF = $context->builder->fmul($eightiethF, $nF);
                        $eightiethF = $context->builder->fmul($eightiethF, $nF);
                        $eightiethF = $context->builder->fmul($eightiethF, $nF);
                        $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);

            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $eightiethF = $context->builder->fmul($eightiethF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eightieth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eightieth_cu_ok');
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
            $eightiethF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $eightiethF2Eighth = $context->builder->fmul($eightiethF2Sq, $sqF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
                $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
                    $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
                        $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
                        $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
                        $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
                        $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
                        $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
                        $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
                        $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);

            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $eightiethF2 = $context->builder->fmul($eightiethF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF2
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_eightieth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_eightieth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $eightiethF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $eightiethF3Eighth = $context->builder->fmul($eightiethF3Sq, $sqF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
                $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
                    $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
                        $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
                        $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
                        $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
                        $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
                        $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
                        $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
                        $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);

            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $eightiethF3 = $context->builder->fmul($eightiethF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF3
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_eightieth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_eightieth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $eightiethF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $eightiethF4Eighth = $context->builder->fmul($eightiethF4Sq, $sqF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
                $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
                    $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
                        $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
                        $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
                        $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
                        $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
                        $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
                        $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
                        $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);

            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $eightiethF4 = $context->builder->fmul($eightiethF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF4
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_eightieth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_eightieth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $eightiethF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $eightiethF5Eighth = $context->builder->fmul($eightiethF5Sq, $sqF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
                $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
                    $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
                        $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
                        $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
                        $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
                        $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
                        $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
                        $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
                        $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);

            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $eightiethF5 = $context->builder->fmul($eightiethF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF5
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_eightieth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_eightieth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $eightiethF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $eightiethF6Eighth = $context->builder->fmul($eightiethF6Sq, $sqF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
                $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
                    $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
                        $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
                        $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
                        $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
                        $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
                        $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
                        $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
                        $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);

            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $eightiethF6 = $context->builder->fmul($eightiethF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF6
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_eightieth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_eightieth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $eightiethF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $eightiethF7Eighth = $context->builder->fmul($eightiethF7Sq, $sqF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
                $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
                    $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
                        $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
                        $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
                        $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
                        $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
                        $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
                        $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
                        $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);

            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $eightiethF7 = $context->builder->fmul($eightiethF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF7
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_eightieth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_eightieth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $eightiethF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $eightiethF8Eighth = $context->builder->fmul($eightiethF8Sq, $sqF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
                $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
                    $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
                        $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
                        $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
                        $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
                        $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
                        $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
                        $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
                        $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);

            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $eightiethF8 = $context->builder->fmul($eightiethF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF8
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_eightieth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_eightieth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $eightiethF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
                $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
                    $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
                        $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
                        $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
                        $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
                        $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
                        $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
                        $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
                        $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);

            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $eightiethF9 = $context->builder->fmul($eightiethF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF9
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_eightieth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_eightieth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $eightiethF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
                $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
                    $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
                        $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
                        $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
                        $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
                        $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
                        $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
                        $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
                        $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);

            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $eightiethF10 = $context->builder->fmul($eightiethF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF10
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $eightiethF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
                $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
                    $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
                        $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
                        $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
                        $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
                        $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
                        $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
                        $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
                        $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);

            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $eightiethF11 = $context->builder->fmul($eightiethF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF11
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $eightiethF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
                $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
                    $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
                        $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
                        $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
                        $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
                        $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
                        $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
                        $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
                        $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);

            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $eightiethF12 = $context->builder->fmul($eightiethF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF12
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $eightiethF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
                $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
                    $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
                        $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
                        $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
                        $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
                        $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
                        $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
                        $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
                        $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);

            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $eightiethF13 = $context->builder->fmul($eightiethF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF13
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $eightiethF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
                $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
                    $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
                        $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
                        $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
                        $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
                        $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
                        $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
                        $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
                        $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);

            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $eightiethF14 = $context->builder->fmul($eightiethF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF14
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $eightiethF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
                $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
                    $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
                        $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
                        $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
                        $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
                        $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
                        $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
                        $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
                        $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);

            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $eightiethF15 = $context->builder->fmul($eightiethF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF15
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $eightiethF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
                $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
                    $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
                        $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
                        $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
                        $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
                        $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
                        $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
                        $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
                        $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);

            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $eightiethF16 = $context->builder->fmul($eightiethF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF16
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $eightiethF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
                $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
                    $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
                        $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
                        $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
                        $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
                        $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
                        $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
                        $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
                        $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);

            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $eightiethF17 = $context->builder->fmul($eightiethF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF17
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $eightiethF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
                $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
                    $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
                        $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
                        $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
                        $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
                        $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
                        $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
                        $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
                        $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);

            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $eightiethF18 = $context->builder->fmul($eightiethF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF18
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $eightiethF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
                $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
                    $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
                        $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
                        $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
                        $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
                        $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
                        $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
                        $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
                        $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);

            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $eightiethF19 = $context->builder->fmul($eightiethF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF19
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_eightieth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $eightiethF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
                    $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
                        $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
                        $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
                        $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
                        $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
                        $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
                        $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
                        $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);

            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $eightiethF20 = $context->builder->fmul($eightiethF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF20
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $eightiethF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
                    $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
                    $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
                    $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
                    $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
                    $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
                    $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
                    $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);

            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $eightiethF21 = $context->builder->fmul($eightiethF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF21
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $eightiethF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
                $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
                $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
                $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
                $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
                $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
                $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);

            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $eightiethF22 = $context->builder->fmul($eightiethF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF22
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $eightiethF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);

            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $eightiethF23 = $context->builder->fmul($eightiethF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF23
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $eightiethF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);

            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $eightiethF24 = $context->builder->fmul($eightiethF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF24
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $eightiethF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);

            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $eightiethF25 = $context->builder->fmul($eightiethF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF25
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $eightiethF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);

            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $eightiethF26 = $context->builder->fmul($eightiethF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF26
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $eightiethF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);

            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $eightiethF27 = $context->builder->fmul($eightiethF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF27
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $eightiethF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);

            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $eightiethF28 = $context->builder->fmul($eightiethF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF28
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $eightiethF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);

            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $eightiethF29 = $context->builder->fmul($eightiethF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF29
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_eightieth_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $eightiethF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $eightiethF30 = $context->builder->fmul($eightiethF30, $nF30);
            $eightiethF30 = $context->builder->fmul($eightiethF30, $nF30);
            $eightiethF30 = $context->builder->fmul($eightiethF30, $nF30);
            $eightiethF30 = $context->builder->fmul($eightiethF30, $nF30);
            $eightiethF30 = $context->builder->fmul($eightiethF30, $nF30);
            $eightiethF30 = $context->builder->fmul($eightiethF30, $nF30);
            $eightiethF30 = $context->builder->fmul($eightiethF30, $nF30);
            $eightiethF30 = $context->builder->fmul($eightiethF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF30
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_eightieth_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_eightieth_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $eightiethF31 = $context->builder->fmul($seventiethF31, $nF31);
            $eightiethF31 = $context->builder->fmul($eightiethF31, $nF31);
            $eightiethF31 = $context->builder->fmul($eightiethF31, $nF31);
            $eightiethF31 = $context->builder->fmul($eightiethF31, $nF31);
            $eightiethF31 = $context->builder->fmul($eightiethF31, $nF31);
            $eightiethF31 = $context->builder->fmul($eightiethF31, $nF31);
            $eightiethF31 = $context->builder->fmul($eightiethF31, $nF31);
            $eightiethF31 = $context->builder->fmul($eightiethF31, $nF31);
            $eightiethF31 = $context->builder->fmul($eightiethF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF31
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $eightiethF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $eightiethF32 = $context->builder->fmul($eightiethF32, $nF32);
            $eightiethF32 = $context->builder->fmul($eightiethF32, $nF32);
            $eightiethF32 = $context->builder->fmul($eightiethF32, $nF32);
            $eightiethF32 = $context->builder->fmul($eightiethF32, $nF32);
            $eightiethF32 = $context->builder->fmul($eightiethF32, $nF32);
            $eightiethF32 = $context->builder->fmul($eightiethF32, $nF32);
            $eightiethF32 = $context->builder->fmul($eightiethF32, $nF32);
            $eightiethF32 = $context->builder->fmul($eightiethF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF32
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_eightieth_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_eightieth_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $eightiethF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightiethF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightiethF33 = $context->builder->fmul($eightiethF33, $nF33);
            $eightiethF33 = $context->builder->fmul($eightiethF33, $nF33);
            $eightiethF33 = $context->builder->fmul($eightiethF33, $nF33);
            $eightiethF33 = $context->builder->fmul($eightiethF33, $nF33);
            $eightiethF33 = $context->builder->fmul($eightiethF33, $nF33);
            $eightiethF33 = $context->builder->fmul($eightiethF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF33
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_eightieth_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_eightieth_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $eightiethF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $eightiethF34 = $context->builder->fmul($eightiethF34, $nF34);
            $eightiethF34 = $context->builder->fmul($eightiethF34, $nF34);
            $eightiethF34 = $context->builder->fmul($eightiethF34, $nF34);
            $eightiethF34 = $context->builder->fmul($eightiethF34, $nF34);
            $eightiethF34 = $context->builder->fmul($eightiethF34, $nF34);
            $eightiethF34 = $context->builder->fmul($eightiethF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF34
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $eightiethF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightiethF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightiethF35 = $context->builder->fmul($eightiethF35, $nF35);
            $eightiethF35 = $context->builder->fmul($eightiethF35, $nF35);
            $eightiethF35 = $context->builder->fmul($eightiethF35, $nF35);
            $eightiethF35 = $context->builder->fmul($eightiethF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF35
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $eightiethF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $eightiethF36 = $context->builder->fmul($eightiethF36, $nF36);
            $eightiethF36 = $context->builder->fmul($eightiethF36, $nF36);
            $eightiethF36 = $context->builder->fmul($eightiethF36, $nF36);
            $eightiethF36 = $context->builder->fmul($eightiethF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF36
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_eightieth_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_eightieth_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $eightiethF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $eightiethF37 = $context->builder->fmul($eightiethF37, $nF37);
            $eightiethF37 = $context->builder->fmul($eightiethF37, $nF37);
            $eightiethF37 = $context->builder->fmul($eightiethF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF37
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $eightiethF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightiethF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightiethF38 = $context->builder->fmul($eightiethF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF38
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $eightiethF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $eightiethF39 = $context->builder->fmul($eightiethF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF39
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
                throw new \LogicException('pow() **80 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_eightieth_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $eightiethF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightiethF40
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok40Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $seventyninthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('eightyfirst' === $expFold) {
            // n^81 = eightieth*n; overflow arms +1 ×nF vs **80.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **80.
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eightyfirst_done');
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
            $eightyfirstFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $eightyfirstFEighth = $context->builder->fmul($eightyfirstFSq, $sqF);
            $eightyfirstF = $context->builder->fmul($eightyfirstFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
                $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
                    $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
                        $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
                        $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
                        $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
                        $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
                        $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
                        $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
                        $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);

            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $eightyfirstF = $context->builder->fmul($eightyfirstF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eightyfirst_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eightyfirst_cu_ok');
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
            $eightyfirstF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $eightyfirstF2Eighth = $context->builder->fmul($eightyfirstF2Sq, $sqF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
                $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
                    $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
                        $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
                        $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
                        $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
                        $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
                        $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
                        $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
                        $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);

            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $eightyfirstF2 = $context->builder->fmul($eightyfirstF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF2
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $eightyfirstF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $eightyfirstF3Eighth = $context->builder->fmul($eightyfirstF3Sq, $sqF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
                $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
                    $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
                        $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
                        $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
                        $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
                        $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
                        $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
                        $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
                        $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);

            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $eightyfirstF3 = $context->builder->fmul($eightyfirstF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF3
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_eightyfirst_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_eightyfirst_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $eightyfirstF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $eightyfirstF4Eighth = $context->builder->fmul($eightyfirstF4Sq, $sqF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
                $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
                    $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
                        $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
                        $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
                        $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
                        $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
                        $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
                        $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
                        $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);

            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $eightyfirstF4 = $context->builder->fmul($eightyfirstF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF4
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_eightyfirst_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_eightyfirst_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $eightyfirstF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $eightyfirstF5Eighth = $context->builder->fmul($eightyfirstF5Sq, $sqF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
                $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
                    $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
                        $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
                        $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
                        $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
                        $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
                        $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
                        $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
                        $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);

            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $eightyfirstF5 = $context->builder->fmul($eightyfirstF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF5
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $eightyfirstF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $eightyfirstF6Eighth = $context->builder->fmul($eightyfirstF6Sq, $sqF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
                $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
                    $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
                        $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
                        $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
                        $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
                        $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
                        $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
                        $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
                        $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);

            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $eightyfirstF6 = $context->builder->fmul($eightyfirstF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF6
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $eightyfirstF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $eightyfirstF7Eighth = $context->builder->fmul($eightyfirstF7Sq, $sqF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
                $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
                    $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
                        $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
                        $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
                        $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
                        $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
                        $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
                        $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
                        $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);

            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $eightyfirstF7 = $context->builder->fmul($eightyfirstF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF7
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $eightyfirstF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $eightyfirstF8Eighth = $context->builder->fmul($eightyfirstF8Sq, $sqF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
                $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
                    $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
                        $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
                        $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
                        $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
                        $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
                        $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
                        $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
                        $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);

            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $eightyfirstF8 = $context->builder->fmul($eightyfirstF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF8
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $eightyfirstF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
                $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
                    $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
                        $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
                        $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
                        $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
                        $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
                        $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
                        $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
                        $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);

            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $eightyfirstF9 = $context->builder->fmul($eightyfirstF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF9
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $eightyfirstF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
                $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
                    $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
                        $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
                        $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
                        $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
                        $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
                        $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
                        $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
                        $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);

            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $eightyfirstF10 = $context->builder->fmul($eightyfirstF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF10
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $eightyfirstF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
                $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
                    $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
                        $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
                        $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
                        $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
                        $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
                        $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
                        $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
                        $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);

            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $eightyfirstF11 = $context->builder->fmul($eightyfirstF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF11
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $eightyfirstF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
                $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
                    $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
                        $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
                        $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
                        $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
                        $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
                        $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
                        $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
                        $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);

            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $eightyfirstF12 = $context->builder->fmul($eightyfirstF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF12
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $eightyfirstF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
                $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
                    $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
                        $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
                        $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
                        $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
                        $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
                        $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
                        $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
                        $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);

            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $eightyfirstF13 = $context->builder->fmul($eightyfirstF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF13
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $eightyfirstF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
                $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
                    $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
                        $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
                        $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
                        $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
                        $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
                        $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
                        $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
                        $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);

            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $eightyfirstF14 = $context->builder->fmul($eightyfirstF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF14
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $eightyfirstF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
                $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
                    $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
                        $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
                        $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
                        $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
                        $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
                        $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
                        $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
                        $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);

            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $eightyfirstF15 = $context->builder->fmul($eightyfirstF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF15
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $eightyfirstF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
                $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
                    $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
                        $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
                        $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
                        $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
                        $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
                        $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
                        $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
                        $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);

            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $eightyfirstF16 = $context->builder->fmul($eightyfirstF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF16
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $eightyfirstF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
                $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
                    $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
                        $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
                        $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
                        $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
                        $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
                        $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
                        $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
                        $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);

            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $eightyfirstF17 = $context->builder->fmul($eightyfirstF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF17
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $eightyfirstF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
                $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
                    $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
                        $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
                        $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
                        $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
                        $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
                        $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
                        $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
                        $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);

            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $eightyfirstF18 = $context->builder->fmul($eightyfirstF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF18
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $eightyfirstF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
                $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
                    $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
                        $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
                        $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
                        $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
                        $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
                        $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
                        $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
                        $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);

            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $eightyfirstF19 = $context->builder->fmul($eightyfirstF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF19
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_eightyfirst_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $eightyfirstF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
                    $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
                        $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
                        $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
                        $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
                        $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
                        $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
                        $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
                        $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);

            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $eightyfirstF20 = $context->builder->fmul($eightyfirstF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF20
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $eightyfirstF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
                    $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
                    $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
                    $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
                    $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
                    $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
                    $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
                    $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);

            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $eightyfirstF21 = $context->builder->fmul($eightyfirstF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF21
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $eightyfirstF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
                $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
                $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
                $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
                $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
                $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
                $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);

            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $eightyfirstF22 = $context->builder->fmul($eightyfirstF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF22
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $eightyfirstF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);

            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $eightyfirstF23 = $context->builder->fmul($eightyfirstF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF23
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $eightyfirstF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);

            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $eightyfirstF24 = $context->builder->fmul($eightyfirstF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF24
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $eightyfirstF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);

            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $eightyfirstF25 = $context->builder->fmul($eightyfirstF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF25
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $eightyfirstF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);

            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $eightyfirstF26 = $context->builder->fmul($eightyfirstF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF26
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $eightyfirstF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);

            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $eightyfirstF27 = $context->builder->fmul($eightyfirstF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF27
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $eightyfirstF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);

            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $eightyfirstF28 = $context->builder->fmul($eightyfirstF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF28
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $eightyfirstF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);

            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $eightyfirstF29 = $context->builder->fmul($eightyfirstF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF29
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_eightyfirst_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $eightyfirstF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $eightyfirstF30 = $context->builder->fmul($eightyfirstF30, $nF30);
            $eightyfirstF30 = $context->builder->fmul($eightyfirstF30, $nF30);
            $eightyfirstF30 = $context->builder->fmul($eightyfirstF30, $nF30);
            $eightyfirstF30 = $context->builder->fmul($eightyfirstF30, $nF30);
            $eightyfirstF30 = $context->builder->fmul($eightyfirstF30, $nF30);
            $eightyfirstF30 = $context->builder->fmul($eightyfirstF30, $nF30);
            $eightyfirstF30 = $context->builder->fmul($eightyfirstF30, $nF30);
            $eightyfirstF30 = $context->builder->fmul($eightyfirstF30, $nF30);
            $eightyfirstF30 = $context->builder->fmul($eightyfirstF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF30
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $eightyfirstF31 = $context->builder->fmul($seventiethF31, $nF31);
            $eightyfirstF31 = $context->builder->fmul($eightyfirstF31, $nF31);
            $eightyfirstF31 = $context->builder->fmul($eightyfirstF31, $nF31);
            $eightyfirstF31 = $context->builder->fmul($eightyfirstF31, $nF31);
            $eightyfirstF31 = $context->builder->fmul($eightyfirstF31, $nF31);
            $eightyfirstF31 = $context->builder->fmul($eightyfirstF31, $nF31);
            $eightyfirstF31 = $context->builder->fmul($eightyfirstF31, $nF31);
            $eightyfirstF31 = $context->builder->fmul($eightyfirstF31, $nF31);
            $eightyfirstF31 = $context->builder->fmul($eightyfirstF31, $nF31);
            $eightyfirstF31 = $context->builder->fmul($eightyfirstF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF31
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $eightyfirstF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $eightyfirstF32 = $context->builder->fmul($eightyfirstF32, $nF32);
            $eightyfirstF32 = $context->builder->fmul($eightyfirstF32, $nF32);
            $eightyfirstF32 = $context->builder->fmul($eightyfirstF32, $nF32);
            $eightyfirstF32 = $context->builder->fmul($eightyfirstF32, $nF32);
            $eightyfirstF32 = $context->builder->fmul($eightyfirstF32, $nF32);
            $eightyfirstF32 = $context->builder->fmul($eightyfirstF32, $nF32);
            $eightyfirstF32 = $context->builder->fmul($eightyfirstF32, $nF32);
            $eightyfirstF32 = $context->builder->fmul($eightyfirstF32, $nF32);
            $eightyfirstF32 = $context->builder->fmul($eightyfirstF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF32
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $eightyfirstF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightyfirstF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightyfirstF33 = $context->builder->fmul($eightyfirstF33, $nF33);
            $eightyfirstF33 = $context->builder->fmul($eightyfirstF33, $nF33);
            $eightyfirstF33 = $context->builder->fmul($eightyfirstF33, $nF33);
            $eightyfirstF33 = $context->builder->fmul($eightyfirstF33, $nF33);
            $eightyfirstF33 = $context->builder->fmul($eightyfirstF33, $nF33);
            $eightyfirstF33 = $context->builder->fmul($eightyfirstF33, $nF33);
            $eightyfirstF33 = $context->builder->fmul($eightyfirstF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF33
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $eightyfirstF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $eightyfirstF34 = $context->builder->fmul($eightyfirstF34, $nF34);
            $eightyfirstF34 = $context->builder->fmul($eightyfirstF34, $nF34);
            $eightyfirstF34 = $context->builder->fmul($eightyfirstF34, $nF34);
            $eightyfirstF34 = $context->builder->fmul($eightyfirstF34, $nF34);
            $eightyfirstF34 = $context->builder->fmul($eightyfirstF34, $nF34);
            $eightyfirstF34 = $context->builder->fmul($eightyfirstF34, $nF34);
            $eightyfirstF34 = $context->builder->fmul($eightyfirstF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF34
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $eightyfirstF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightyfirstF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightyfirstF35 = $context->builder->fmul($eightyfirstF35, $nF35);
            $eightyfirstF35 = $context->builder->fmul($eightyfirstF35, $nF35);
            $eightyfirstF35 = $context->builder->fmul($eightyfirstF35, $nF35);
            $eightyfirstF35 = $context->builder->fmul($eightyfirstF35, $nF35);
            $eightyfirstF35 = $context->builder->fmul($eightyfirstF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF35
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $eightyfirstF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $eightyfirstF36 = $context->builder->fmul($eightyfirstF36, $nF36);
            $eightyfirstF36 = $context->builder->fmul($eightyfirstF36, $nF36);
            $eightyfirstF36 = $context->builder->fmul($eightyfirstF36, $nF36);
            $eightyfirstF36 = $context->builder->fmul($eightyfirstF36, $nF36);
            $eightyfirstF36 = $context->builder->fmul($eightyfirstF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF36
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $eightyfirstF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $eightyfirstF37 = $context->builder->fmul($eightyfirstF37, $nF37);
            $eightyfirstF37 = $context->builder->fmul($eightyfirstF37, $nF37);
            $eightyfirstF37 = $context->builder->fmul($eightyfirstF37, $nF37);
            $eightyfirstF37 = $context->builder->fmul($eightyfirstF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF37
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $eightyfirstF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightyfirstF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightyfirstF38 = $context->builder->fmul($eightyfirstF38, $nF38);
            $eightyfirstF38 = $context->builder->fmul($eightyfirstF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF38
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $eightyfirstF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $eightyfirstF39 = $context->builder->fmul($eightyfirstF39, $nF39);
            $eightyfirstF39 = $context->builder->fmul($eightyfirstF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF39
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_eightyfirst_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $eightyfirstF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $eightyfirstF40 = $context->builder->fmul($eightyfirstF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF40
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
                throw new \LogicException('pow() **81 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_eightyfirst_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_eightyfirst_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $eightyfirstF41 = $context->builder->fmul($eightiethF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfirstF41
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok41Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eightiethLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('eightysecond' === $expFold) {
            // n^82 = eightyfirst*n; overflow arms +1 ×nF vs **81.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **82.
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eightysecond_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eightysecond_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eightysecond_done');
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
            $eightysecondFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $eightysecondFEighth = $context->builder->fmul($eightysecondFSq, $sqF);
            $eightysecondF = $context->builder->fmul($eightysecondFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
                $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
                    $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
                        $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
                        $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
                        $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
                        $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
                        $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
                        $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
                        $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);

            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $eightysecondF = $context->builder->fmul($eightysecondF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eightysecond_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eightysecond_cu_ok');
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
            $eightysecondF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $eightysecondF2Eighth = $context->builder->fmul($eightysecondF2Sq, $sqF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
                $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
                    $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
                        $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
                        $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
                        $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
                        $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
                        $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
                        $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
                        $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);

            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $eightysecondF2 = $context->builder->fmul($eightysecondF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF2
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_eightysecond_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_eightysecond_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $eightysecondF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $eightysecondF3Eighth = $context->builder->fmul($eightysecondF3Sq, $sqF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
                $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
                    $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
                        $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
                        $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
                        $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
                        $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
                        $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
                        $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
                        $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);

            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $eightysecondF3 = $context->builder->fmul($eightysecondF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF3
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_eightysecond_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_eightysecond_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $eightysecondF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $eightysecondF4Eighth = $context->builder->fmul($eightysecondF4Sq, $sqF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
                $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
                    $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
                        $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
                        $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
                        $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
                        $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
                        $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
                        $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
                        $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);

            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $eightysecondF4 = $context->builder->fmul($eightysecondF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF4
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_eightysecond_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_eightysecond_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $eightysecondF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $eightysecondF5Eighth = $context->builder->fmul($eightysecondF5Sq, $sqF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
                $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
                    $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
                        $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
                        $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
                        $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
                        $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
                        $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
                        $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
                        $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);

            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $eightysecondF5 = $context->builder->fmul($eightysecondF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF5
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $eightysecondF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $eightysecondF6Eighth = $context->builder->fmul($eightysecondF6Sq, $sqF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
                $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
                    $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
                        $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
                        $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
                        $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
                        $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
                        $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
                        $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
                        $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);

            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $eightysecondF6 = $context->builder->fmul($eightysecondF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF6
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $eightysecondF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $eightysecondF7Eighth = $context->builder->fmul($eightysecondF7Sq, $sqF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
                $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
                    $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
                        $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
                        $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
                        $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
                        $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
                        $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
                        $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
                        $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);

            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $eightysecondF7 = $context->builder->fmul($eightysecondF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF7
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $eightysecondF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $eightysecondF8Eighth = $context->builder->fmul($eightysecondF8Sq, $sqF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
                $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
                    $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
                        $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
                        $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
                        $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
                        $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
                        $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
                        $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
                        $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);

            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $eightysecondF8 = $context->builder->fmul($eightysecondF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF8
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $eightysecondF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
                $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
                    $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
                        $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
                        $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
                        $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
                        $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
                        $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
                        $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
                        $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);

            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $eightysecondF9 = $context->builder->fmul($eightysecondF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF9
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_eightysecond_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $eightysecondF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
                $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
                    $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
                        $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
                        $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
                        $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
                        $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
                        $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
                        $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
                        $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);

            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $eightysecondF10 = $context->builder->fmul($eightysecondF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF10
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $eightysecondF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
                $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
                    $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
                        $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
                        $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
                        $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
                        $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
                        $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
                        $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
                        $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);

            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $eightysecondF11 = $context->builder->fmul($eightysecondF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF11
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $eightysecondF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
                $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
                    $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
                        $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
                        $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
                        $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
                        $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
                        $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
                        $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
                        $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);

            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $eightysecondF12 = $context->builder->fmul($eightysecondF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF12
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $eightysecondF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
                $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
                    $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
                        $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
                        $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
                        $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
                        $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
                        $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
                        $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
                        $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);

            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $eightysecondF13 = $context->builder->fmul($eightysecondF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF13
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $eightysecondF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
                $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
                    $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
                        $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
                        $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
                        $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
                        $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
                        $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
                        $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
                        $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);

            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $eightysecondF14 = $context->builder->fmul($eightysecondF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF14
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $eightysecondF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
                $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
                    $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
                        $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
                        $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
                        $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
                        $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
                        $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
                        $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
                        $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);

            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $eightysecondF15 = $context->builder->fmul($eightysecondF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF15
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $eightysecondF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
                $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
                    $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
                        $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
                        $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
                        $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
                        $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
                        $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
                        $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
                        $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);

            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $eightysecondF16 = $context->builder->fmul($eightysecondF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF16
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $eightysecondF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
                $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
                    $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
                        $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
                        $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
                        $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
                        $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
                        $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
                        $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
                        $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);

            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $eightysecondF17 = $context->builder->fmul($eightysecondF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF17
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $eightysecondF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
                $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
                    $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
                        $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
                        $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
                        $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
                        $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
                        $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
                        $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
                        $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);

            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $eightysecondF18 = $context->builder->fmul($eightysecondF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF18
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $eightysecondF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
                $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
                    $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
                        $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
                        $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
                        $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
                        $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
                        $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
                        $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
                        $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);

            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $eightysecondF19 = $context->builder->fmul($eightysecondF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF19
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_eightysecond_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $eightysecondF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
                    $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
                        $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
                        $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
                        $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
                        $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
                        $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
                        $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
                        $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);

            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $eightysecondF20 = $context->builder->fmul($eightysecondF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF20
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $eightysecondF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
                    $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
                    $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
                    $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
                    $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
                    $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
                    $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
                    $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);

            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $eightysecondF21 = $context->builder->fmul($eightysecondF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF21
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $eightysecondF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
                $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
                $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
                $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
                $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
                $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
                $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);

            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $eightysecondF22 = $context->builder->fmul($eightysecondF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF22
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $eightysecondF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);

            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $eightysecondF23 = $context->builder->fmul($eightysecondF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF23
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $eightysecondF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);

            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $eightysecondF24 = $context->builder->fmul($eightysecondF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF24
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $eightysecondF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);

            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $eightysecondF25 = $context->builder->fmul($eightysecondF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF25
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $eightysecondF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);

            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $eightysecondF26 = $context->builder->fmul($eightysecondF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF26
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $eightysecondF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);

            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $eightysecondF27 = $context->builder->fmul($eightysecondF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF27
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $eightysecondF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);

            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $eightysecondF28 = $context->builder->fmul($eightysecondF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF28
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $eightysecondF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);

            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $eightysecondF29 = $context->builder->fmul($eightysecondF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF29
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_eightysecond_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $eightysecondF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $eightysecondF30 = $context->builder->fmul($eightysecondF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF30
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $eightysecondF31 = $context->builder->fmul($seventiethF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $eightysecondF31 = $context->builder->fmul($eightysecondF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF31
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $eightysecondF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $eightysecondF32 = $context->builder->fmul($eightysecondF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF32
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $eightysecondF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightysecondF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightysecondF33 = $context->builder->fmul($eightysecondF33, $nF33);
            $eightysecondF33 = $context->builder->fmul($eightysecondF33, $nF33);
            $eightysecondF33 = $context->builder->fmul($eightysecondF33, $nF33);
            $eightysecondF33 = $context->builder->fmul($eightysecondF33, $nF33);
            $eightysecondF33 = $context->builder->fmul($eightysecondF33, $nF33);
            $eightysecondF33 = $context->builder->fmul($eightysecondF33, $nF33);
            $eightysecondF33 = $context->builder->fmul($eightysecondF33, $nF33);
            $eightysecondF33 = $context->builder->fmul($eightysecondF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF33
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $eightysecondF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $eightysecondF34 = $context->builder->fmul($eightysecondF34, $nF34);
            $eightysecondF34 = $context->builder->fmul($eightysecondF34, $nF34);
            $eightysecondF34 = $context->builder->fmul($eightysecondF34, $nF34);
            $eightysecondF34 = $context->builder->fmul($eightysecondF34, $nF34);
            $eightysecondF34 = $context->builder->fmul($eightysecondF34, $nF34);
            $eightysecondF34 = $context->builder->fmul($eightysecondF34, $nF34);
            $eightysecondF34 = $context->builder->fmul($eightysecondF34, $nF34);
            $eightysecondF34 = $context->builder->fmul($eightysecondF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF34
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $eightysecondF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightysecondF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightysecondF35 = $context->builder->fmul($eightysecondF35, $nF35);
            $eightysecondF35 = $context->builder->fmul($eightysecondF35, $nF35);
            $eightysecondF35 = $context->builder->fmul($eightysecondF35, $nF35);
            $eightysecondF35 = $context->builder->fmul($eightysecondF35, $nF35);
            $eightysecondF35 = $context->builder->fmul($eightysecondF35, $nF35);
            $eightysecondF35 = $context->builder->fmul($eightysecondF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF35
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $eightysecondF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $eightysecondF36 = $context->builder->fmul($eightysecondF36, $nF36);
            $eightysecondF36 = $context->builder->fmul($eightysecondF36, $nF36);
            $eightysecondF36 = $context->builder->fmul($eightysecondF36, $nF36);
            $eightysecondF36 = $context->builder->fmul($eightysecondF36, $nF36);
            $eightysecondF36 = $context->builder->fmul($eightysecondF36, $nF36);
            $eightysecondF36 = $context->builder->fmul($eightysecondF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF36
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $eightysecondF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $eightysecondF37 = $context->builder->fmul($eightysecondF37, $nF37);
            $eightysecondF37 = $context->builder->fmul($eightysecondF37, $nF37);
            $eightysecondF37 = $context->builder->fmul($eightysecondF37, $nF37);
            $eightysecondF37 = $context->builder->fmul($eightysecondF37, $nF37);
            $eightysecondF37 = $context->builder->fmul($eightysecondF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF37
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $eightysecondF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightysecondF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightysecondF38 = $context->builder->fmul($eightysecondF38, $nF38);
            $eightysecondF38 = $context->builder->fmul($eightysecondF38, $nF38);
            $eightysecondF38 = $context->builder->fmul($eightysecondF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF38
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $eightysecondF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $eightysecondF39 = $context->builder->fmul($eightysecondF39, $nF39);
            $eightysecondF39 = $context->builder->fmul($eightysecondF39, $nF39);
            $eightysecondF39 = $context->builder->fmul($eightysecondF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF39
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_eightysecond_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $eightysecondF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $eightysecondF40 = $context->builder->fmul($eightysecondF40, $nF40);
            $eightysecondF40 = $context->builder->fmul($eightysecondF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF40
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_eightysecond_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_eightysecond_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $eightysecondF41 = $context->builder->fmul($eightiethF41, $nF41);
            $eightysecondF41 = $context->builder->fmul($eightiethF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF41
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
                throw new \LogicException('pow() **82 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_eightysecond_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_eightysecond_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $eightysecondF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightysecondF42
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok42Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eightyfirstLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('eightythird' === $expFold) {
            // n^83 = eightysecond*n; overflow arms +1 ×nF vs **82.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **83.
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eightythird_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eightythird_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eightythird_done');
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
            $eightythirdFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $eightythirdFEighth = $context->builder->fmul($eightythirdFSq, $sqF);
            $eightythirdF = $context->builder->fmul($eightythirdFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
                $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
                    $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
                        $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
                        $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
                        $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
                        $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
                        $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
                        $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
                        $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);

            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $eightythirdF = $context->builder->fmul($eightythirdF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eightythird_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eightythird_cu_ok');
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
            $eightythirdF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $eightythirdF2Eighth = $context->builder->fmul($eightythirdF2Sq, $sqF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
                $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
                    $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
                        $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
                        $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
                        $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
                        $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
                        $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
                        $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
                        $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);

            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $eightythirdF2 = $context->builder->fmul($eightythirdF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF2
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_eightythird_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_eightythird_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $eightythirdF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $eightythirdF3Eighth = $context->builder->fmul($eightythirdF3Sq, $sqF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
                $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
                    $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
                        $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
                        $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
                        $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
                        $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
                        $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
                        $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
                        $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);

            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $eightythirdF3 = $context->builder->fmul($eightythirdF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF3
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_eightythird_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_eightythird_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $eightythirdF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $eightythirdF4Eighth = $context->builder->fmul($eightythirdF4Sq, $sqF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
                $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
                    $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
                        $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
                        $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
                        $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
                        $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
                        $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
                        $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
                        $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);

            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $eightythirdF4 = $context->builder->fmul($eightythirdF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF4
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_eightythird_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_eightythird_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $eightythirdF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $eightythirdF5Eighth = $context->builder->fmul($eightythirdF5Sq, $sqF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
                $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
                    $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
                        $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
                        $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
                        $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
                        $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
                        $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
                        $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
                        $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);

            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $eightythirdF5 = $context->builder->fmul($eightythirdF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF5
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_eightythird_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_eightythird_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $eightythirdF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $eightythirdF6Eighth = $context->builder->fmul($eightythirdF6Sq, $sqF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
                $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
                    $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
                        $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
                        $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
                        $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
                        $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
                        $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
                        $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
                        $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);

            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $eightythirdF6 = $context->builder->fmul($eightythirdF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF6
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_eightythird_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_eightythird_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $eightythirdF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $eightythirdF7Eighth = $context->builder->fmul($eightythirdF7Sq, $sqF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
                $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
                    $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
                        $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
                        $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
                        $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
                        $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
                        $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
                        $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
                        $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);

            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $eightythirdF7 = $context->builder->fmul($eightythirdF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF7
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_eightythird_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_eightythird_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $eightythirdF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $eightythirdF8Eighth = $context->builder->fmul($eightythirdF8Sq, $sqF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
                $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
                    $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
                        $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
                        $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
                        $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
                        $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
                        $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
                        $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
                        $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);

            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $eightythirdF8 = $context->builder->fmul($eightythirdF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF8
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_eightythird_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_eightythird_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $eightythirdF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
                $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
                    $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
                        $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
                        $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
                        $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
                        $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
                        $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
                        $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
                        $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);

            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $eightythirdF9 = $context->builder->fmul($eightythirdF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF9
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_eightythird_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_eightythird_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $eightythirdF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
                $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
                    $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
                        $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
                        $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
                        $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
                        $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
                        $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
                        $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
                        $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);

            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $eightythirdF10 = $context->builder->fmul($eightythirdF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF10
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $eightythirdF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
                $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
                    $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
                        $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
                        $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
                        $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
                        $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
                        $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
                        $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
                        $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);

            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $eightythirdF11 = $context->builder->fmul($eightythirdF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF11
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $eightythirdF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
                $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
                    $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
                        $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
                        $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
                        $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
                        $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
                        $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
                        $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
                        $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);

            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $eightythirdF12 = $context->builder->fmul($eightythirdF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF12
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $eightythirdF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
                $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
                    $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
                        $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
                        $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
                        $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
                        $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
                        $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
                        $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
                        $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);

            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $eightythirdF13 = $context->builder->fmul($eightythirdF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF13
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $eightythirdF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
                $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
                    $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
                        $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
                        $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
                        $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
                        $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
                        $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
                        $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
                        $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);

            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $eightythirdF14 = $context->builder->fmul($eightythirdF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF14
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $eightythirdF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
                $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
                    $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
                        $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
                        $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
                        $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
                        $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
                        $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
                        $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
                        $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);

            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $eightythirdF15 = $context->builder->fmul($eightythirdF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF15
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $eightythirdF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
                $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
                    $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
                        $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
                        $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
                        $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
                        $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
                        $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
                        $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
                        $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);

            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $eightythirdF16 = $context->builder->fmul($eightythirdF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF16
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $eightythirdF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
                $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
                    $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
                        $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
                        $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
                        $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
                        $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
                        $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
                        $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
                        $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);

            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $eightythirdF17 = $context->builder->fmul($eightythirdF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF17
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $eightythirdF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
                $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
                    $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
                        $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
                        $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
                        $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
                        $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
                        $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
                        $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
                        $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);

            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $eightythirdF18 = $context->builder->fmul($eightythirdF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF18
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $eightythirdF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
                $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
                    $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
                        $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
                        $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
                        $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
                        $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
                        $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
                        $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
                        $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);

            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $eightythirdF19 = $context->builder->fmul($eightythirdF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF19
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_eightythird_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $eightythirdF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
                    $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
                        $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
                        $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
                        $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
                        $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
                        $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
                        $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
                        $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);

            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $eightythirdF20 = $context->builder->fmul($eightythirdF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF20
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $eightythirdF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
                    $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
                    $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
                    $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
                    $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
                    $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
                    $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
                    $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);

            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $eightythirdF21 = $context->builder->fmul($eightythirdF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF21
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $eightythirdF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
                $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
                $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
                $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
                $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
                $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
                $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);

            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $eightythirdF22 = $context->builder->fmul($eightythirdF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF22
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $eightythirdF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);

            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $eightythirdF23 = $context->builder->fmul($eightythirdF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF23
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $eightythirdF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);

            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $eightythirdF24 = $context->builder->fmul($eightythirdF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF24
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $eightythirdF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);

            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $eightythirdF25 = $context->builder->fmul($eightythirdF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF25
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $eightythirdF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);

            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $eightythirdF26 = $context->builder->fmul($eightythirdF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF26
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $eightythirdF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);

            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $eightythirdF27 = $context->builder->fmul($eightythirdF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF27
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $eightythirdF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);

            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $eightythirdF28 = $context->builder->fmul($eightythirdF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF28
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $eightythirdF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);

            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $eightythirdF29 = $context->builder->fmul($eightythirdF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF29
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_eightythird_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $eightythirdF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $eightythirdF30 = $context->builder->fmul($eightythirdF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF30
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_eightythird_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_eightythird_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $eightythirdF31 = $context->builder->fmul($seventiethF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $eightythirdF31 = $context->builder->fmul($eightythirdF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF31
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $eightythirdF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $eightythirdF32 = $context->builder->fmul($eightythirdF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF32
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_eightythird_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_eightythird_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $eightythirdF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($eightythirdF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($eightythirdF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($eightythirdF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($eightythirdF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($eightythirdF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($eightythirdF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($eightythirdF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($eightythirdF33, $nF33);
            $eightythirdF33 = $context->builder->fmul($eightythirdF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF33
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_eightythird_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_eightythird_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $eightythirdF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $eightythirdF34 = $context->builder->fmul($eightythirdF34, $nF34);
            $eightythirdF34 = $context->builder->fmul($eightythirdF34, $nF34);
            $eightythirdF34 = $context->builder->fmul($eightythirdF34, $nF34);
            $eightythirdF34 = $context->builder->fmul($eightythirdF34, $nF34);
            $eightythirdF34 = $context->builder->fmul($eightythirdF34, $nF34);
            $eightythirdF34 = $context->builder->fmul($eightythirdF34, $nF34);
            $eightythirdF34 = $context->builder->fmul($eightythirdF34, $nF34);
            $eightythirdF34 = $context->builder->fmul($eightythirdF34, $nF34);
            $eightythirdF34 = $context->builder->fmul($eightythirdF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF34
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $eightythirdF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightythirdF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightythirdF35 = $context->builder->fmul($eightythirdF35, $nF35);
            $eightythirdF35 = $context->builder->fmul($eightythirdF35, $nF35);
            $eightythirdF35 = $context->builder->fmul($eightythirdF35, $nF35);
            $eightythirdF35 = $context->builder->fmul($eightythirdF35, $nF35);
            $eightythirdF35 = $context->builder->fmul($eightythirdF35, $nF35);
            $eightythirdF35 = $context->builder->fmul($eightythirdF35, $nF35);
            $eightythirdF35 = $context->builder->fmul($eightythirdF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF35
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $eightythirdF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $eightythirdF36 = $context->builder->fmul($eightythirdF36, $nF36);
            $eightythirdF36 = $context->builder->fmul($eightythirdF36, $nF36);
            $eightythirdF36 = $context->builder->fmul($eightythirdF36, $nF36);
            $eightythirdF36 = $context->builder->fmul($eightythirdF36, $nF36);
            $eightythirdF36 = $context->builder->fmul($eightythirdF36, $nF36);
            $eightythirdF36 = $context->builder->fmul($eightythirdF36, $nF36);
            $eightythirdF36 = $context->builder->fmul($eightythirdF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF36
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_eightythird_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_eightythird_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $eightythirdF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $eightythirdF37 = $context->builder->fmul($eightythirdF37, $nF37);
            $eightythirdF37 = $context->builder->fmul($eightythirdF37, $nF37);
            $eightythirdF37 = $context->builder->fmul($eightythirdF37, $nF37);
            $eightythirdF37 = $context->builder->fmul($eightythirdF37, $nF37);
            $eightythirdF37 = $context->builder->fmul($eightythirdF37, $nF37);
            $eightythirdF37 = $context->builder->fmul($eightythirdF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF37
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $eightythirdF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightythirdF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightythirdF38 = $context->builder->fmul($eightythirdF38, $nF38);
            $eightythirdF38 = $context->builder->fmul($eightythirdF38, $nF38);
            $eightythirdF38 = $context->builder->fmul($eightythirdF38, $nF38);
            $eightythirdF38 = $context->builder->fmul($eightythirdF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF38
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $eightythirdF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $eightythirdF39 = $context->builder->fmul($eightythirdF39, $nF39);
            $eightythirdF39 = $context->builder->fmul($eightythirdF39, $nF39);
            $eightythirdF39 = $context->builder->fmul($eightythirdF39, $nF39);
            $eightythirdF39 = $context->builder->fmul($eightythirdF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF39
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_eightythird_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $eightythirdF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $eightythirdF40 = $context->builder->fmul($eightythirdF40, $nF40);
            $eightythirdF40 = $context->builder->fmul($eightythirdF40, $nF40);
            $eightythirdF40 = $context->builder->fmul($eightythirdF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF40
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_eightythird_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_eightythird_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $eightythirdF41 = $context->builder->fmul($eightiethF41, $nF41);
            $eightythirdF41 = $context->builder->fmul($eightythirdF41, $nF41);
            $eightythirdF41 = $context->builder->fmul($eightythirdF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF41
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_eightythird_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_eightythird_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $eightythirdF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $eightythirdF42 = $context->builder->fmul($eightythirdF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF42
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
                throw new \LogicException('pow() **83 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_eightythird_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_eightythird_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $eightythirdF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightythirdF43
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok43Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eightysecondLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('eightyfourth' === $expFold) {
            // n^84 = eightythird*n; overflow arms +1 ×nF vs **83.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **84.
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eightyfourth_done');
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
            $eightyfourthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $eightyfourthFEighth = $context->builder->fmul($eightyfourthFSq, $sqF);
            $eightyfourthF = $context->builder->fmul($eightyfourthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
                $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
                    $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
                        $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
                        $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
                        $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
                        $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
                        $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
                        $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
                        $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);

            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $eightyfourthF = $context->builder->fmul($eightyfourthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eightyfourth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eightyfourth_cu_ok');
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
            $eightyfourthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $eightyfourthF2Eighth = $context->builder->fmul($eightyfourthF2Sq, $sqF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
                $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
                    $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
                        $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
                        $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
                        $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
                        $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
                        $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
                        $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
                        $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);

            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $eightyfourthF2 = $context->builder->fmul($eightyfourthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF2
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $eightyfourthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $eightyfourthF3Eighth = $context->builder->fmul($eightyfourthF3Sq, $sqF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
                $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
                    $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
                        $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
                        $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
                        $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
                        $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
                        $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
                        $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
                        $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);

            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $eightyfourthF3 = $context->builder->fmul($eightyfourthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF3
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_eightyfourth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_eightyfourth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $eightyfourthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $eightyfourthF4Eighth = $context->builder->fmul($eightyfourthF4Sq, $sqF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
                $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
                    $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
                        $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
                        $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
                        $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
                        $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
                        $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
                        $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
                        $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);

            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $eightyfourthF4 = $context->builder->fmul($eightyfourthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF4
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_eightyfourth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_eightyfourth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $eightyfourthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $eightyfourthF5Eighth = $context->builder->fmul($eightyfourthF5Sq, $sqF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
                $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
                    $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
                        $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
                        $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
                        $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
                        $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
                        $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
                        $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
                        $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);

            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $eightyfourthF5 = $context->builder->fmul($eightyfourthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF5
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $eightyfourthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $eightyfourthF6Eighth = $context->builder->fmul($eightyfourthF6Sq, $sqF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
                $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
                    $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
                        $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
                        $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
                        $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
                        $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
                        $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
                        $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
                        $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);

            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $eightyfourthF6 = $context->builder->fmul($eightyfourthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF6
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $eightyfourthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $eightyfourthF7Eighth = $context->builder->fmul($eightyfourthF7Sq, $sqF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
                $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
                    $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
                        $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
                        $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
                        $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
                        $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
                        $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
                        $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
                        $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);

            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $eightyfourthF7 = $context->builder->fmul($eightyfourthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF7
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $eightyfourthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $eightyfourthF8Eighth = $context->builder->fmul($eightyfourthF8Sq, $sqF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
                $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
                    $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
                        $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
                        $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
                        $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
                        $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
                        $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
                        $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
                        $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);

            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $eightyfourthF8 = $context->builder->fmul($eightyfourthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF8
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $eightyfourthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
                $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
                    $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
                        $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
                        $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
                        $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
                        $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
                        $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
                        $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
                        $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);

            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $eightyfourthF9 = $context->builder->fmul($eightyfourthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF9
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $eightyfourthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
                $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
                    $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
                        $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
                        $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
                        $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
                        $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
                        $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
                        $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
                        $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);

            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $eightyfourthF10 = $context->builder->fmul($eightyfourthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF10
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $eightyfourthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
                $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
                    $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
                        $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
                        $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
                        $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
                        $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
                        $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
                        $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
                        $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);

            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $eightyfourthF11 = $context->builder->fmul($eightyfourthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF11
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $eightyfourthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
                $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
                    $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
                        $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
                        $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
                        $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
                        $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
                        $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
                        $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
                        $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);

            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $eightyfourthF12 = $context->builder->fmul($eightyfourthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF12
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $eightyfourthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
                $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
                    $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
                        $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
                        $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
                        $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
                        $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
                        $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
                        $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
                        $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);

            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $eightyfourthF13 = $context->builder->fmul($eightyfourthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF13
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $eightyfourthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
                $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
                    $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
                        $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
                        $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
                        $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
                        $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
                        $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
                        $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
                        $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);

            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $eightyfourthF14 = $context->builder->fmul($eightyfourthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF14
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $eightyfourthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
                $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
                    $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
                        $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
                        $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
                        $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
                        $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
                        $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
                        $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
                        $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);

            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $eightyfourthF15 = $context->builder->fmul($eightyfourthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF15
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $eightyfourthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
                $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
                    $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
                        $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
                        $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
                        $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
                        $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
                        $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
                        $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
                        $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);

            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $eightyfourthF16 = $context->builder->fmul($eightyfourthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF16
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $eightyfourthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
                $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
                    $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
                        $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
                        $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
                        $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
                        $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
                        $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
                        $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
                        $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);

            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $eightyfourthF17 = $context->builder->fmul($eightyfourthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF17
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $eightyfourthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
                $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
                    $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
                        $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
                        $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
                        $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
                        $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
                        $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
                        $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
                        $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);

            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $eightyfourthF18 = $context->builder->fmul($eightyfourthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF18
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $eightyfourthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
                $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
                    $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
                        $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
                        $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
                        $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
                        $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
                        $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
                        $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
                        $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);

            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $eightyfourthF19 = $context->builder->fmul($eightyfourthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF19
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_eightyfourth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $eightyfourthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
                    $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
                        $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
                        $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
                        $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
                        $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
                        $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
                        $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
                        $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);

            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $eightyfourthF20 = $context->builder->fmul($eightyfourthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF20
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $eightyfourthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
                    $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
                    $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
                    $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
                    $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
                    $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
                    $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
                    $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);

            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $eightyfourthF21 = $context->builder->fmul($eightyfourthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF21
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $eightyfourthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
                $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
                $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
                $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
                $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
                $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
                $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);

            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $eightyfourthF22 = $context->builder->fmul($eightyfourthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF22
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $eightyfourthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);

            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $eightyfourthF23 = $context->builder->fmul($eightyfourthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF23
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $eightyfourthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);

            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $eightyfourthF24 = $context->builder->fmul($eightyfourthF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF24
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $eightyfourthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);

            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $eightyfourthF25 = $context->builder->fmul($eightyfourthF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF25
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $eightyfourthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);

            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $eightyfourthF26 = $context->builder->fmul($eightyfourthF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF26
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $eightyfourthF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);

            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $eightyfourthF27 = $context->builder->fmul($eightyfourthF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF27
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $eightyfourthF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);

            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $eightyfourthF28 = $context->builder->fmul($eightyfourthF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF28
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $eightyfourthF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);

            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $eightyfourthF29 = $context->builder->fmul($eightyfourthF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF29
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_eightyfourth_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $eightyfourthF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $eightyfourthF30 = $context->builder->fmul($eightyfourthF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF30
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $eightyfourthF31 = $context->builder->fmul($seventiethF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $eightyfourthF31 = $context->builder->fmul($eightyfourthF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF31
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $eightyfourthF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $eightyfourthF32 = $context->builder->fmul($eightyfourthF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF32
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $eightyfourthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $eightyfourthF33 = $context->builder->fmul($eightyfourthF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF33
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $eightyfourthF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $eightyfourthF34 = $context->builder->fmul($eightyfourthF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF34
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $eightyfourthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightyfourthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightyfourthF35 = $context->builder->fmul($eightyfourthF35, $nF35);
            $eightyfourthF35 = $context->builder->fmul($eightyfourthF35, $nF35);
            $eightyfourthF35 = $context->builder->fmul($eightyfourthF35, $nF35);
            $eightyfourthF35 = $context->builder->fmul($eightyfourthF35, $nF35);
            $eightyfourthF35 = $context->builder->fmul($eightyfourthF35, $nF35);
            $eightyfourthF35 = $context->builder->fmul($eightyfourthF35, $nF35);
            $eightyfourthF35 = $context->builder->fmul($eightyfourthF35, $nF35);
            $eightyfourthF35 = $context->builder->fmul($eightyfourthF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF35
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $eightyfourthF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $eightyfourthF36 = $context->builder->fmul($eightyfourthF36, $nF36);
            $eightyfourthF36 = $context->builder->fmul($eightyfourthF36, $nF36);
            $eightyfourthF36 = $context->builder->fmul($eightyfourthF36, $nF36);
            $eightyfourthF36 = $context->builder->fmul($eightyfourthF36, $nF36);
            $eightyfourthF36 = $context->builder->fmul($eightyfourthF36, $nF36);
            $eightyfourthF36 = $context->builder->fmul($eightyfourthF36, $nF36);
            $eightyfourthF36 = $context->builder->fmul($eightyfourthF36, $nF36);
            $eightyfourthF36 = $context->builder->fmul($eightyfourthF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF36
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $eightyfourthF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $eightyfourthF37 = $context->builder->fmul($eightyfourthF37, $nF37);
            $eightyfourthF37 = $context->builder->fmul($eightyfourthF37, $nF37);
            $eightyfourthF37 = $context->builder->fmul($eightyfourthF37, $nF37);
            $eightyfourthF37 = $context->builder->fmul($eightyfourthF37, $nF37);
            $eightyfourthF37 = $context->builder->fmul($eightyfourthF37, $nF37);
            $eightyfourthF37 = $context->builder->fmul($eightyfourthF37, $nF37);
            $eightyfourthF37 = $context->builder->fmul($eightyfourthF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF37
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $eightyfourthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightyfourthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightyfourthF38 = $context->builder->fmul($eightyfourthF38, $nF38);
            $eightyfourthF38 = $context->builder->fmul($eightyfourthF38, $nF38);
            $eightyfourthF38 = $context->builder->fmul($eightyfourthF38, $nF38);
            $eightyfourthF38 = $context->builder->fmul($eightyfourthF38, $nF38);
            $eightyfourthF38 = $context->builder->fmul($eightyfourthF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF38
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $eightyfourthF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $eightyfourthF39 = $context->builder->fmul($eightyfourthF39, $nF39);
            $eightyfourthF39 = $context->builder->fmul($eightyfourthF39, $nF39);
            $eightyfourthF39 = $context->builder->fmul($eightyfourthF39, $nF39);
            $eightyfourthF39 = $context->builder->fmul($eightyfourthF39, $nF39);
            $eightyfourthF39 = $context->builder->fmul($eightyfourthF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF39
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_eightyfourth_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $eightyfourthF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $eightyfourthF40 = $context->builder->fmul($eightyfourthF40, $nF40);
            $eightyfourthF40 = $context->builder->fmul($eightyfourthF40, $nF40);
            $eightyfourthF40 = $context->builder->fmul($eightyfourthF40, $nF40);
            $eightyfourthF40 = $context->builder->fmul($eightyfourthF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF40
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_eightyfourth_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_eightyfourth_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $eightyfourthF41 = $context->builder->fmul($eightiethF41, $nF41);
            $eightyfourthF41 = $context->builder->fmul($eightyfourthF41, $nF41);
            $eightyfourthF41 = $context->builder->fmul($eightyfourthF41, $nF41);
            $eightyfourthF41 = $context->builder->fmul($eightyfourthF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF41
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_eightyfourth_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_eightyfourth_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $eightyfourthF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $eightyfourthF42 = $context->builder->fmul($eightyfourthF42, $nF42);
            $eightyfourthF42 = $context->builder->fmul($eightyfourthF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF42
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_eightyfourth_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_eightyfourth_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $eightyfourthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $eightyfourthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF43
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
                throw new \LogicException('pow() **84 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_eightyfourth_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_eightyfourth_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $eightyfourthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfourthF44
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok44Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eightythirdLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('eightyfifth' === $expFold) {
            // n^85 = eightyfourth*n; overflow arms +1 ×nF vs **84.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **85.
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eightyfifth_done');
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
            $eightyfifthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $eightyfifthFEighth = $context->builder->fmul($eightyfifthFSq, $sqF);
            $eightyfifthF = $context->builder->fmul($eightyfifthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
                $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
                    $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
                        $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
                        $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
                        $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
                        $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
                        $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
                        $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
                        $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);

            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $eightyfifthF = $context->builder->fmul($eightyfifthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eightyfifth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eightyfifth_cu_ok');
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
            $eightyfifthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $eightyfifthF2Eighth = $context->builder->fmul($eightyfifthF2Sq, $sqF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
                $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
                    $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
                        $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
                        $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
                        $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
                        $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
                        $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
                        $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
                        $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);

            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $eightyfifthF2 = $context->builder->fmul($eightyfifthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF2
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $eightyfifthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $eightyfifthF3Eighth = $context->builder->fmul($eightyfifthF3Sq, $sqF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
                $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
                    $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
                        $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
                        $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
                        $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
                        $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
                        $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
                        $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
                        $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);

            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $eightyfifthF3 = $context->builder->fmul($eightyfifthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF3
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_eightyfifth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_eightyfifth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $eightyfifthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $eightyfifthF4Eighth = $context->builder->fmul($eightyfifthF4Sq, $sqF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
                $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
                    $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
                        $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
                        $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
                        $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
                        $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
                        $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
                        $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
                        $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);

            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $eightyfifthF4 = $context->builder->fmul($eightyfifthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF4
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_eightyfifth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_eightyfifth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $eightyfifthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $eightyfifthF5Eighth = $context->builder->fmul($eightyfifthF5Sq, $sqF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
                $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
                    $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
                        $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
                        $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
                        $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
                        $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
                        $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
                        $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
                        $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);

            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $eightyfifthF5 = $context->builder->fmul($eightyfifthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF5
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $eightyfifthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $eightyfifthF6Eighth = $context->builder->fmul($eightyfifthF6Sq, $sqF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
                $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
                    $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
                        $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
                        $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
                        $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
                        $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
                        $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
                        $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
                        $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);

            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $eightyfifthF6 = $context->builder->fmul($eightyfifthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF6
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $eightyfifthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $eightyfifthF7Eighth = $context->builder->fmul($eightyfifthF7Sq, $sqF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
                $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
                    $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
                        $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
                        $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
                        $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
                        $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
                        $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
                        $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
                        $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);

            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $eightyfifthF7 = $context->builder->fmul($eightyfifthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF7
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $eightyfifthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $eightyfifthF8Eighth = $context->builder->fmul($eightyfifthF8Sq, $sqF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
                $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
                    $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
                        $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
                        $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
                        $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
                        $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
                        $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
                        $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
                        $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);

            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $eightyfifthF8 = $context->builder->fmul($eightyfifthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF8
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $eightyfifthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
                $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
                    $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
                        $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
                        $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
                        $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
                        $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
                        $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
                        $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
                        $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);

            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $eightyfifthF9 = $context->builder->fmul($eightyfifthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF9
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $eightyfifthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
                $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
                    $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
                        $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
                        $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
                        $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
                        $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
                        $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
                        $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
                        $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);

            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $eightyfifthF10 = $context->builder->fmul($eightyfifthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF10
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $eightyfifthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
                $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
                    $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
                        $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
                        $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
                        $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
                        $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
                        $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
                        $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
                        $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);

            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $eightyfifthF11 = $context->builder->fmul($eightyfifthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF11
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $eightyfifthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
                $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
                    $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
                        $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
                        $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
                        $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
                        $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
                        $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
                        $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
                        $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);

            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $eightyfifthF12 = $context->builder->fmul($eightyfifthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF12
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $eightyfifthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
                $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
                    $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
                        $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
                        $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
                        $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
                        $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
                        $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
                        $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
                        $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);

            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $eightyfifthF13 = $context->builder->fmul($eightyfifthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF13
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $eightyfifthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
                $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
                    $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
                        $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
                        $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
                        $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
                        $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
                        $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
                        $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
                        $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);

            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $eightyfifthF14 = $context->builder->fmul($eightyfifthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF14
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $eightyfifthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
                $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
                    $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
                        $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
                        $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
                        $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
                        $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
                        $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
                        $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
                        $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);

            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $eightyfifthF15 = $context->builder->fmul($eightyfifthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF15
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $eightyfifthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
                $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
                    $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
                        $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
                        $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
                        $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
                        $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
                        $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
                        $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
                        $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);

            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $eightyfifthF16 = $context->builder->fmul($eightyfifthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF16
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $eightyfifthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
                $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
                    $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
                        $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
                        $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
                        $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
                        $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
                        $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
                        $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
                        $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);

            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $eightyfifthF17 = $context->builder->fmul($eightyfifthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF17
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $eightyfifthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
                $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
                    $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
                        $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
                        $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
                        $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
                        $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
                        $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
                        $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
                        $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);

            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $eightyfifthF18 = $context->builder->fmul($eightyfifthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF18
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $eightyfifthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
                $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
                    $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
                        $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
                        $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
                        $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
                        $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
                        $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
                        $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
                        $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);

            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $eightyfifthF19 = $context->builder->fmul($eightyfifthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF19
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_eightyfifth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $eightyfifthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
                    $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
                        $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
                        $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
                        $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
                        $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
                        $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
                        $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
                        $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);

            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $eightyfifthF20 = $context->builder->fmul($eightyfifthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF20
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $eightyfifthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
                    $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
                    $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
                    $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
                    $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
                    $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
                    $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
                    $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);

            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $eightyfifthF21 = $context->builder->fmul($eightyfifthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF21
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $eightyfifthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
                $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
                $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
                $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
                $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
                $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
                $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);

            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $eightyfifthF22 = $context->builder->fmul($eightyfifthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF22
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $eightyfifthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);

            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $eightyfifthF23 = $context->builder->fmul($eightyfifthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF23
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $eightyfifthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);

            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $eightyfifthF24 = $context->builder->fmul($eightyfifthF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF24
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $eightyfifthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);

            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $eightyfifthF25 = $context->builder->fmul($eightyfifthF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF25
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $eightyfifthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);

            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $eightyfifthF26 = $context->builder->fmul($eightyfifthF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF26
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $eightyfifthF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);

            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $eightyfifthF27 = $context->builder->fmul($eightyfifthF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF27
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $eightyfifthF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);

            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $eightyfifthF28 = $context->builder->fmul($eightyfifthF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF28
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $eightyfifthF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);

            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $eightyfifthF29 = $context->builder->fmul($eightyfifthF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF29
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_eightyfifth_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $eightyfifthF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $eightyfifthF30 = $context->builder->fmul($eightyfifthF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF30
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $eightyfifthF31 = $context->builder->fmul($seventiethF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $eightyfifthF31 = $context->builder->fmul($eightyfifthF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF31
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $eightyfifthF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $eightyfifthF32 = $context->builder->fmul($eightyfifthF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF32
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $eightyfifthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $eightyfifthF33 = $context->builder->fmul($eightyfifthF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF33
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $eightyfifthF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $eightyfifthF34 = $context->builder->fmul($eightyfifthF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF34
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $eightyfifthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($eightyfifthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($eightyfifthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($eightyfifthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($eightyfifthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($eightyfifthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($eightyfifthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($eightyfifthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($eightyfifthF35, $nF35);
            $eightyfifthF35 = $context->builder->fmul($eightyfifthF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF35
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $eightyfifthF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $eightyfifthF36 = $context->builder->fmul($eightyfifthF36, $nF36);
            $eightyfifthF36 = $context->builder->fmul($eightyfifthF36, $nF36);
            $eightyfifthF36 = $context->builder->fmul($eightyfifthF36, $nF36);
            $eightyfifthF36 = $context->builder->fmul($eightyfifthF36, $nF36);
            $eightyfifthF36 = $context->builder->fmul($eightyfifthF36, $nF36);
            $eightyfifthF36 = $context->builder->fmul($eightyfifthF36, $nF36);
            $eightyfifthF36 = $context->builder->fmul($eightyfifthF36, $nF36);
            $eightyfifthF36 = $context->builder->fmul($eightyfifthF36, $nF36);
            $eightyfifthF36 = $context->builder->fmul($eightyfifthF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF36
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $eightyfifthF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $eightyfifthF37 = $context->builder->fmul($eightyfifthF37, $nF37);
            $eightyfifthF37 = $context->builder->fmul($eightyfifthF37, $nF37);
            $eightyfifthF37 = $context->builder->fmul($eightyfifthF37, $nF37);
            $eightyfifthF37 = $context->builder->fmul($eightyfifthF37, $nF37);
            $eightyfifthF37 = $context->builder->fmul($eightyfifthF37, $nF37);
            $eightyfifthF37 = $context->builder->fmul($eightyfifthF37, $nF37);
            $eightyfifthF37 = $context->builder->fmul($eightyfifthF37, $nF37);
            $eightyfifthF37 = $context->builder->fmul($eightyfifthF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF37
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $eightyfifthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightyfifthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightyfifthF38 = $context->builder->fmul($eightyfifthF38, $nF38);
            $eightyfifthF38 = $context->builder->fmul($eightyfifthF38, $nF38);
            $eightyfifthF38 = $context->builder->fmul($eightyfifthF38, $nF38);
            $eightyfifthF38 = $context->builder->fmul($eightyfifthF38, $nF38);
            $eightyfifthF38 = $context->builder->fmul($eightyfifthF38, $nF38);
            $eightyfifthF38 = $context->builder->fmul($eightyfifthF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF38
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $eightyfifthF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $eightyfifthF39 = $context->builder->fmul($eightyfifthF39, $nF39);
            $eightyfifthF39 = $context->builder->fmul($eightyfifthF39, $nF39);
            $eightyfifthF39 = $context->builder->fmul($eightyfifthF39, $nF39);
            $eightyfifthF39 = $context->builder->fmul($eightyfifthF39, $nF39);
            $eightyfifthF39 = $context->builder->fmul($eightyfifthF39, $nF39);
            $eightyfifthF39 = $context->builder->fmul($eightyfifthF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF39
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_eightyfifth_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $eightyfifthF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $eightyfifthF40 = $context->builder->fmul($eightyfifthF40, $nF40);
            $eightyfifthF40 = $context->builder->fmul($eightyfifthF40, $nF40);
            $eightyfifthF40 = $context->builder->fmul($eightyfifthF40, $nF40);
            $eightyfifthF40 = $context->builder->fmul($eightyfifthF40, $nF40);
            $eightyfifthF40 = $context->builder->fmul($eightyfifthF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF40
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $eightyfifthF41 = $context->builder->fmul($eightiethF41, $nF41);
            $eightyfifthF41 = $context->builder->fmul($eightyfifthF41, $nF41);
            $eightyfifthF41 = $context->builder->fmul($eightyfifthF41, $nF41);
            $eightyfifthF41 = $context->builder->fmul($eightyfifthF41, $nF41);
            $eightyfifthF41 = $context->builder->fmul($eightyfifthF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF41
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $eightyfifthF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $eightyfifthF42 = $context->builder->fmul($eightyfifthF42, $nF42);
            $eightyfifthF42 = $context->builder->fmul($eightyfifthF42, $nF42);
            $eightyfifthF42 = $context->builder->fmul($eightyfifthF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF42
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $eightyfifthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $eightyfifthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $eightyfifthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF43
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $eightyfifthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $eightyfifthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF44
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
                throw new \LogicException('pow() **85 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_eightyfifth_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $eightyfifthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyfifthF45
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok45Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eightyfourthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }


        throw new \LogicException('JitPowIntegerEmitExponents80to85: unhandled expFold '.$expFold);
    }
}
