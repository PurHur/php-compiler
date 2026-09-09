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
 * for eightyninth … ninetyfirst (#36387 / #36386).
 *
 * Sibling of {@see JitPowIntegerEmitExponents86to88}; 92–95 in
 * {@see JitPowIntegerEmitExponents92to95}; High retains ninetysixth+.
 *
 * No new C ABI. php-src: Zend/zend_operators.c {@code pow_function} /
 * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
 * {@code PHP_FUNCTION(pow)}.
 */
final class JitPowIntegerEmitExponents80to91
{
    /**
     * @return bool true when {@code $expFold} was an 89–91 exponent and emit ran
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
            'eightyninth' => true,
            'ninetieth' => true,
            'ninetyfirst' => true,
        ];
        if (!isset($exponents[$expFold])) {
            return false;
        }

        if ('eightyninth' === $expFold) {
            // n^89 = eightyeighth*n; overflow arms +1 ×nF vs **88.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **89.
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eightyninth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eightyninth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eightyninth_done');
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
            $eightyninthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $eightyninthFEighth = $context->builder->fmul($eightyninthFSq, $sqF);
            $eightyninthF = $context->builder->fmul($eightyninthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
                $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
                    $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
                        $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
                        $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
                        $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
                        $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
                        $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
                        $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
                        $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);

            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $eightyninthF = $context->builder->fmul($eightyninthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eightyninth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eightyninth_cu_ok');
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
            $eightyninthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $eightyninthF2Eighth = $context->builder->fmul($eightyninthF2Sq, $sqF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
                $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
                    $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
                        $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
                        $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
                        $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
                        $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
                        $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
                        $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
                        $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);

            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $eightyninthF2 = $context->builder->fmul($eightyninthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF2
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_eightyninth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_eightyninth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $eightyninthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $eightyninthF3Eighth = $context->builder->fmul($eightyninthF3Sq, $sqF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
                $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
                    $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
                        $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
                        $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
                        $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
                        $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
                        $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
                        $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
                        $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);

            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $eightyninthF3 = $context->builder->fmul($eightyninthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF3
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_eightyninth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_eightyninth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $eightyninthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $eightyninthF4Eighth = $context->builder->fmul($eightyninthF4Sq, $sqF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
                $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
                    $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
                        $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
                        $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
                        $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
                        $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
                        $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
                        $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
                        $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);

            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $eightyninthF4 = $context->builder->fmul($eightyninthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF4
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_eightyninth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_eightyninth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $eightyninthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $eightyninthF5Eighth = $context->builder->fmul($eightyninthF5Sq, $sqF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
                $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
                    $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
                        $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
                        $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
                        $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
                        $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
                        $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
                        $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
                        $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);

            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $eightyninthF5 = $context->builder->fmul($eightyninthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF5
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $eightyninthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $eightyninthF6Eighth = $context->builder->fmul($eightyninthF6Sq, $sqF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
                $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
                    $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
                        $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
                        $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
                        $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
                        $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
                        $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
                        $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
                        $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);

            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $eightyninthF6 = $context->builder->fmul($eightyninthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF6
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $eightyninthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $eightyninthF7Eighth = $context->builder->fmul($eightyninthF7Sq, $sqF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
                $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
                    $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
                        $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
                        $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
                        $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
                        $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
                        $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
                        $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
                        $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);

            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $eightyninthF7 = $context->builder->fmul($eightyninthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF7
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $eightyninthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $eightyninthF8Eighth = $context->builder->fmul($eightyninthF8Sq, $sqF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
                $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
                    $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
                        $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
                        $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
                        $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
                        $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
                        $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
                        $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
                        $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);

            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $eightyninthF8 = $context->builder->fmul($eightyninthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF8
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $eightyninthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
                $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
                    $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
                        $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
                        $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
                        $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
                        $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
                        $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
                        $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
                        $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);

            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $eightyninthF9 = $context->builder->fmul($eightyninthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF9
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_eightyninth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $eightyninthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
                $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
                    $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
                        $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
                        $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
                        $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
                        $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
                        $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
                        $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
                        $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);

            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $eightyninthF10 = $context->builder->fmul($eightyninthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF10
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $eightyninthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
                $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
                    $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
                        $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
                        $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
                        $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
                        $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
                        $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
                        $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
                        $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);

            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $eightyninthF11 = $context->builder->fmul($eightyninthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF11
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $eightyninthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
                $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
                    $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
                        $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
                        $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
                        $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
                        $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
                        $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
                        $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
                        $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);

            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $eightyninthF12 = $context->builder->fmul($eightyninthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF12
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $eightyninthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
                $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
                    $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
                        $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
                        $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
                        $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
                        $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
                        $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
                        $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
                        $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);

            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $eightyninthF13 = $context->builder->fmul($eightyninthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF13
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $eightyninthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
                $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
                    $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
                        $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
                        $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
                        $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
                        $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
                        $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
                        $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
                        $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);

            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $eightyninthF14 = $context->builder->fmul($eightyninthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF14
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $eightyninthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
                $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
                    $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
                        $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
                        $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
                        $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
                        $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
                        $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
                        $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
                        $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);

            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $eightyninthF15 = $context->builder->fmul($eightyninthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF15
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $eightyninthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
                $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
                    $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
                        $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
                        $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
                        $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
                        $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
                        $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
                        $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
                        $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);

            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $eightyninthF16 = $context->builder->fmul($eightyninthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF16
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $eightyninthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
                $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
                    $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
                        $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
                        $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
                        $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
                        $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
                        $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
                        $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
                        $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);

            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $eightyninthF17 = $context->builder->fmul($eightyninthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF17
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $eightyninthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
                $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
                    $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
                        $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
                        $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
                        $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
                        $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
                        $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
                        $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
                        $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);

            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $eightyninthF18 = $context->builder->fmul($eightyninthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF18
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $eightyninthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
                $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
                    $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
                        $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
                        $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
                        $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
                        $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
                        $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
                        $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
                        $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);

            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $eightyninthF19 = $context->builder->fmul($eightyninthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF19
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_eightyninth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $eightyninthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
                    $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
                        $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
                        $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
                        $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
                        $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
                        $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
                        $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
                        $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);

            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $eightyninthF20 = $context->builder->fmul($eightyninthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF20
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $eightyninthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
                    $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
                    $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
                    $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
                    $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
                    $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
                    $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
                    $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);

            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $eightyninthF21 = $context->builder->fmul($eightyninthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF21
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $eightyninthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
                $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
                $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
                $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
                $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
                $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
                $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);

            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $eightyninthF22 = $context->builder->fmul($eightyninthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF22
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $eightyninthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);

            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $eightyninthF23 = $context->builder->fmul($eightyninthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF23
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $eightyninthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);

            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $eightyninthF24 = $context->builder->fmul($eightyninthF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF24
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $eightyninthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);

            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $eightyninthF25 = $context->builder->fmul($eightyninthF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF25
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $eightyninthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);

            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $eightyninthF26 = $context->builder->fmul($eightyninthF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF26
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $eightyninthF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);

            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $eightyninthF27 = $context->builder->fmul($eightyninthF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF27
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $eightyninthF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);

            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $eightyninthF28 = $context->builder->fmul($eightyninthF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF28
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $eightyninthF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);

            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $eightyninthF29 = $context->builder->fmul($eightyninthF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF29
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_eightyninth_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $eightyninthF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $eightyninthF30 = $context->builder->fmul($eightyninthF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF30
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $eightyninthF31 = $context->builder->fmul($seventiethF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $eightyninthF31 = $context->builder->fmul($eightyninthF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF31
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $eightyninthF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $eightyninthF32 = $context->builder->fmul($eightyninthF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF32
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $eightyninthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $eightyninthF33 = $context->builder->fmul($eightyninthF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF33
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $eightyninthF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $eightyninthF34 = $context->builder->fmul($eightyninthF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF34
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $eightyninthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $eightyninthF35 = $context->builder->fmul($eightyninthF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF35
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $eightyninthF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $eightyninthF36 = $context->builder->fmul($eightyninthF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF36
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $eightyninthF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $eightyninthF37 = $context->builder->fmul($eightyninthF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF37
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $eightyninthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $eightyninthF38 = $context->builder->fmul($eightyninthF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF38
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $eightyninthF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $eightyninthF39 = $context->builder->fmul($eightyninthF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF39
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_eightyninth_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $eightyninthF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $eightyninthF40 = $context->builder->fmul($eightyninthF40, $nF40);
            $eightyninthF40 = $context->builder->fmul($eightyninthF40, $nF40);
            $eightyninthF40 = $context->builder->fmul($eightyninthF40, $nF40);
            $eightyninthF40 = $context->builder->fmul($eightyninthF40, $nF40);
            $eightyninthF40 = $context->builder->fmul($eightyninthF40, $nF40);
            $eightyninthF40 = $context->builder->fmul($eightyninthF40, $nF40);
            $eightyninthF40 = $context->builder->fmul($eightyninthF40, $nF40);
            $eightyninthF40 = $context->builder->fmul($eightyninthF40, $nF40);
            $eightyninthF40 = $context->builder->fmul($eightyninthF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF40
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $eightyninthF41 = $context->builder->fmul($eightiethF41, $nF41);
            $eightyninthF41 = $context->builder->fmul($eightyninthF41, $nF41);
            $eightyninthF41 = $context->builder->fmul($eightyninthF41, $nF41);
            $eightyninthF41 = $context->builder->fmul($eightyninthF41, $nF41);
            $eightyninthF41 = $context->builder->fmul($eightyninthF41, $nF41);
            $eightyninthF41 = $context->builder->fmul($eightyninthF41, $nF41);
            $eightyninthF41 = $context->builder->fmul($eightyninthF41, $nF41);
            $eightyninthF41 = $context->builder->fmul($eightyninthF41, $nF41);
            $eightyninthF41 = $context->builder->fmul($eightyninthF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF41
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $eightyninthF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $eightyninthF42 = $context->builder->fmul($eightyninthF42, $nF42);
            $eightyninthF42 = $context->builder->fmul($eightyninthF42, $nF42);
            $eightyninthF42 = $context->builder->fmul($eightyninthF42, $nF42);
            $eightyninthF42 = $context->builder->fmul($eightyninthF42, $nF42);
            $eightyninthF42 = $context->builder->fmul($eightyninthF42, $nF42);
            $eightyninthF42 = $context->builder->fmul($eightyninthF42, $nF42);
            $eightyninthF42 = $context->builder->fmul($eightyninthF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF42
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $eightyninthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $eightyninthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $eightyninthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $eightyninthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $eightyninthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $eightyninthF43 = $context->builder->fmul($eightyninthF43, $nF43);
            $eightyninthF43 = $context->builder->fmul($eightyninthF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF43
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $eightyninthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $eightyninthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $eightyninthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $eightyninthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $eightyninthF44 = $context->builder->fmul($eightyninthF44, $nF44);
            $eightyninthF44 = $context->builder->fmul($eightyninthF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF44
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $eightyninthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $eightyninthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $eightyninthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $eightyninthF45 = $context->builder->fmul($eightyninthF45, $nF45);
            $eightyninthF45 = $context->builder->fmul($eightyninthF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF45
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $eightyninthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $eightyninthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $eightyninthF46 = $context->builder->fmul($eightyninthF46, $nF46);
            $eightyninthF46 = $context->builder->fmul($eightyninthF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF46
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $eightyninthF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $eightyninthF47 = $context->builder->fmul($eightyninthF47, $nF47);
            $eightyninthF47 = $context->builder->fmul($eightyninthF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF47
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $eightyninthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $eightyninthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF48
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
                throw new \LogicException('pow() **89 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_eightyninth_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $eightyninthF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eightyninthF49
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok49Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eightyeighthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('ninetieth' === $expFold) {
            // n^90 = eightyninth*n; overflow arms +1 ×nF vs **89.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **90.
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_ninetieth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_ninetieth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_ninetieth_done');
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
            $ninetiethFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $ninetiethFEighth = $context->builder->fmul($ninetiethFSq, $sqF);
            $ninetiethF = $context->builder->fmul($ninetiethFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
                $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
                    $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
                        $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
                        $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
                        $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
                        $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
                        $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
                        $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
                        $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);

            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $ninetiethF = $context->builder->fmul($ninetiethF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_ninetieth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_ninetieth_cu_ok');
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
            $ninetiethF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $ninetiethF2Eighth = $context->builder->fmul($ninetiethF2Sq, $sqF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
                $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
                    $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
                        $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
                        $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
                        $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
                        $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
                        $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
                        $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
                        $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);

            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $ninetiethF2 = $context->builder->fmul($ninetiethF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF2
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_ninetieth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_ninetieth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $ninetiethF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $ninetiethF3Eighth = $context->builder->fmul($ninetiethF3Sq, $sqF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
                $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
                    $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
                        $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
                        $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
                        $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
                        $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
                        $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
                        $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
                        $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);

            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $ninetiethF3 = $context->builder->fmul($ninetiethF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF3
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_ninetieth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_ninetieth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $ninetiethF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $ninetiethF4Eighth = $context->builder->fmul($ninetiethF4Sq, $sqF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
                $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
                    $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
                        $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
                        $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
                        $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
                        $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
                        $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
                        $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
                        $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);

            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $ninetiethF4 = $context->builder->fmul($ninetiethF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF4
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_ninetieth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_ninetieth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $ninetiethF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $ninetiethF5Eighth = $context->builder->fmul($ninetiethF5Sq, $sqF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
                $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
                    $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
                        $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
                        $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
                        $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
                        $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
                        $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
                        $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
                        $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);

            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $ninetiethF5 = $context->builder->fmul($ninetiethF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF5
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $ninetiethF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $ninetiethF6Eighth = $context->builder->fmul($ninetiethF6Sq, $sqF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
                $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
                    $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
                        $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
                        $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
                        $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
                        $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
                        $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
                        $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
                        $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);

            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $ninetiethF6 = $context->builder->fmul($ninetiethF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF6
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $ninetiethF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $ninetiethF7Eighth = $context->builder->fmul($ninetiethF7Sq, $sqF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
                $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
                    $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
                        $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
                        $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
                        $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
                        $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
                        $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
                        $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
                        $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);

            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $ninetiethF7 = $context->builder->fmul($ninetiethF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF7
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $ninetiethF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $ninetiethF8Eighth = $context->builder->fmul($ninetiethF8Sq, $sqF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
                $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
                    $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
                        $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
                        $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
                        $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
                        $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
                        $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
                        $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
                        $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);

            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $ninetiethF8 = $context->builder->fmul($ninetiethF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF8
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $ninetiethF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
                $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
                    $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
                        $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
                        $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
                        $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
                        $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
                        $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
                        $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
                        $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);

            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $ninetiethF9 = $context->builder->fmul($ninetiethF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF9
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_ninetieth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $ninetiethF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
                $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
                    $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
                        $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
                        $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
                        $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
                        $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
                        $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
                        $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
                        $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);

            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $ninetiethF10 = $context->builder->fmul($ninetiethF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF10
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $ninetiethF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
                $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
                    $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
                        $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
                        $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
                        $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
                        $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
                        $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
                        $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
                        $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);

            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $ninetiethF11 = $context->builder->fmul($ninetiethF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF11
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $ninetiethF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
                $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
                    $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
                        $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
                        $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
                        $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
                        $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
                        $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
                        $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
                        $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);

            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $ninetiethF12 = $context->builder->fmul($ninetiethF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF12
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $ninetiethF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
                $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
                    $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
                        $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
                        $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
                        $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
                        $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
                        $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
                        $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
                        $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);

            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $ninetiethF13 = $context->builder->fmul($ninetiethF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF13
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $ninetiethF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
                $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
                    $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
                        $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
                        $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
                        $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
                        $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
                        $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
                        $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
                        $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);

            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $ninetiethF14 = $context->builder->fmul($ninetiethF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF14
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $ninetiethF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
                $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
                    $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
                        $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
                        $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
                        $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
                        $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
                        $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
                        $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
                        $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);

            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $ninetiethF15 = $context->builder->fmul($ninetiethF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF15
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $ninetiethF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
                $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
                    $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
                        $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
                        $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
                        $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
                        $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
                        $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
                        $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
                        $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);

            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $ninetiethF16 = $context->builder->fmul($ninetiethF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF16
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $ninetiethF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
                $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
                    $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
                        $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
                        $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
                        $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
                        $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
                        $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
                        $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
                        $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);

            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $ninetiethF17 = $context->builder->fmul($ninetiethF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF17
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $ninetiethF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
                $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
                    $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
                        $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
                        $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
                        $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
                        $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
                        $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
                        $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
                        $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);

            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $ninetiethF18 = $context->builder->fmul($ninetiethF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF18
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $ninetiethF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
                $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
                    $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
                        $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
                        $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
                        $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
                        $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
                        $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
                        $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
                        $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);

            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $ninetiethF19 = $context->builder->fmul($ninetiethF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF19
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_ninetieth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $ninetiethF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
                    $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
                        $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
                        $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
                        $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
                        $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
                        $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
                        $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
                        $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);

            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $ninetiethF20 = $context->builder->fmul($ninetiethF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF20
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $ninetiethF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
                    $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
                    $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
                    $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
                    $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
                    $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
                    $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
                    $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);

            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $ninetiethF21 = $context->builder->fmul($ninetiethF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF21
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $ninetiethF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
                $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
                $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
                $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
                $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
                $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
                $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);

            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $ninetiethF22 = $context->builder->fmul($ninetiethF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF22
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $ninetiethF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);

            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $ninetiethF23 = $context->builder->fmul($ninetiethF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF23
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $ninetiethF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);

            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $ninetiethF24 = $context->builder->fmul($ninetiethF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF24
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $ninetiethF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);

            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $ninetiethF25 = $context->builder->fmul($ninetiethF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF25
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $ninetiethF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);

            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $ninetiethF26 = $context->builder->fmul($ninetiethF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF26
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $ninetiethF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);

            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $ninetiethF27 = $context->builder->fmul($ninetiethF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF27
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $ninetiethF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);

            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $ninetiethF28 = $context->builder->fmul($ninetiethF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF28
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $ninetiethF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);

            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $ninetiethF29 = $context->builder->fmul($ninetiethF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF29
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_ninetieth_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $ninetiethF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $ninetiethF30 = $context->builder->fmul($ninetiethF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF30
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $ninetiethF31 = $context->builder->fmul($seventiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $ninetiethF31 = $context->builder->fmul($ninetiethF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF31
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $ninetiethF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $ninetiethF32 = $context->builder->fmul($ninetiethF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF32
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $ninetiethF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $ninetiethF33 = $context->builder->fmul($ninetiethF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF33
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $ninetiethF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $ninetiethF34 = $context->builder->fmul($ninetiethF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF34
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $ninetiethF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $ninetiethF35 = $context->builder->fmul($ninetiethF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF35
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $ninetiethF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $ninetiethF36 = $context->builder->fmul($ninetiethF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF36
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $ninetiethF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $ninetiethF37 = $context->builder->fmul($ninetiethF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF37
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $ninetiethF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $ninetiethF38 = $context->builder->fmul($ninetiethF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF38
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $ninetiethF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $ninetiethF39 = $context->builder->fmul($ninetiethF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF39
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_ninetieth_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $ninetiethF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $ninetiethF40 = $context->builder->fmul($ninetiethF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF40
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $ninetiethF41 = $context->builder->fmul($eightiethF41, $nF41);
            $ninetiethF41 = $context->builder->fmul($ninetiethF41, $nF41);
            $ninetiethF41 = $context->builder->fmul($ninetiethF41, $nF41);
            $ninetiethF41 = $context->builder->fmul($ninetiethF41, $nF41);
            $ninetiethF41 = $context->builder->fmul($ninetiethF41, $nF41);
            $ninetiethF41 = $context->builder->fmul($ninetiethF41, $nF41);
            $ninetiethF41 = $context->builder->fmul($ninetiethF41, $nF41);
            $ninetiethF41 = $context->builder->fmul($ninetiethF41, $nF41);
            $ninetiethF41 = $context->builder->fmul($ninetiethF41, $nF41);
            $ninetiethF41 = $context->builder->fmul($ninetiethF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF41
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $ninetiethF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $ninetiethF42 = $context->builder->fmul($ninetiethF42, $nF42);
            $ninetiethF42 = $context->builder->fmul($ninetiethF42, $nF42);
            $ninetiethF42 = $context->builder->fmul($ninetiethF42, $nF42);
            $ninetiethF42 = $context->builder->fmul($ninetiethF42, $nF42);
            $ninetiethF42 = $context->builder->fmul($ninetiethF42, $nF42);
            $ninetiethF42 = $context->builder->fmul($ninetiethF42, $nF42);
            $ninetiethF42 = $context->builder->fmul($ninetiethF42, $nF42);
            $ninetiethF42 = $context->builder->fmul($ninetiethF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF42
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $ninetiethF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetiethF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetiethF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetiethF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetiethF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetiethF43 = $context->builder->fmul($ninetiethF43, $nF43);
            $ninetiethF43 = $context->builder->fmul($ninetiethF43, $nF43);
            $ninetiethF43 = $context->builder->fmul($ninetiethF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF43
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $ninetiethF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetiethF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetiethF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetiethF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetiethF44 = $context->builder->fmul($ninetiethF44, $nF44);
            $ninetiethF44 = $context->builder->fmul($ninetiethF44, $nF44);
            $ninetiethF44 = $context->builder->fmul($ninetiethF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF44
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $ninetiethF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetiethF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetiethF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetiethF45 = $context->builder->fmul($ninetiethF45, $nF45);
            $ninetiethF45 = $context->builder->fmul($ninetiethF45, $nF45);
            $ninetiethF45 = $context->builder->fmul($ninetiethF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF45
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $ninetiethF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetiethF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetiethF46 = $context->builder->fmul($ninetiethF46, $nF46);
            $ninetiethF46 = $context->builder->fmul($ninetiethF46, $nF46);
            $ninetiethF46 = $context->builder->fmul($ninetiethF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF46
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $ninetiethF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $ninetiethF47 = $context->builder->fmul($ninetiethF47, $nF47);
            $ninetiethF47 = $context->builder->fmul($ninetiethF47, $nF47);
            $ninetiethF47 = $context->builder->fmul($ninetiethF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF47
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $ninetiethF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetiethF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetiethF48 = $context->builder->fmul($ninetiethF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF48
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $ninetiethF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $ninetiethF49 = $context->builder->fmul($ninetiethF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF49
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
                throw new \LogicException('pow() **90 expected smul overflow metadata (eightyninth)');
            }
            $eightyninthLong = JITVariable::KIND_VARIABLE === $eightyninthVar->kind
                ? $context->builder->load($eightyninthVar->value)
                : $eightyninthVar->value;
            $ov50Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyninth_ov');
            $ok50Block = BasicBlockHelper::append($context, 'pow_ninetieth_eightyninth_ok');
            $context->builder->branchIf($ov50, $ov50Block, $ok50Block);

            $context->builder->positionAtEnd($ov50Block);
            $eightyninthF50 = $context->builder->load($eightyninthVar->longArithOverflowDoubleSlot);
            $nF50 = $context->builder->siToFp($n, $f64);
            $ninetiethF50 = $context->builder->fmul($eightyninthF50, $nF50);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetiethF50
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok50Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eightyninthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('ninetyfirst' === $expFold) {
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_ninetyfirst_done');
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
            $ninetyfirstFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $ninetyfirstFEighth = $context->builder->fmul($ninetyfirstFSq, $sqF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
                $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
                    $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
                        $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
                        $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
                        $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
                        $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
                        $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
                        $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
                        $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);

            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $ninetyfirstF = $context->builder->fmul($ninetyfirstF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_cu_ok');
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
            $ninetyfirstF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $ninetyfirstF2Eighth = $context->builder->fmul($ninetyfirstF2Sq, $sqF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
                $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
                    $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
                        $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
                        $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
                        $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
                        $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
                        $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
                        $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
                        $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);

            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $ninetyfirstF2 = $context->builder->fmul($ninetyfirstF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF2
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $ninetyfirstF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $ninetyfirstF3Eighth = $context->builder->fmul($ninetyfirstF3Sq, $sqF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
                $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
                    $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
                        $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
                        $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
                        $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
                        $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
                        $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
                        $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
                        $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);

            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $ninetyfirstF3 = $context->builder->fmul($ninetyfirstF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF3
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $ninetyfirstF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $ninetyfirstF4Eighth = $context->builder->fmul($ninetyfirstF4Sq, $sqF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
                $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
                    $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
                        $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
                        $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
                        $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
                        $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
                        $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
                        $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
                        $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);

            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $ninetyfirstF4 = $context->builder->fmul($ninetyfirstF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF4
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $ninetyfirstF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $ninetyfirstF5Eighth = $context->builder->fmul($ninetyfirstF5Sq, $sqF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
                $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
                    $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
                        $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
                        $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
                        $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
                        $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
                        $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
                        $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
                        $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);

            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $ninetyfirstF5 = $context->builder->fmul($ninetyfirstF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF5
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $ninetyfirstF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $ninetyfirstF6Eighth = $context->builder->fmul($ninetyfirstF6Sq, $sqF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
                $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
                    $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
                        $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
                        $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
                        $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
                        $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
                        $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
                        $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
                        $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);

            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $ninetyfirstF6 = $context->builder->fmul($ninetyfirstF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF6
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $ninetyfirstF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $ninetyfirstF7Eighth = $context->builder->fmul($ninetyfirstF7Sq, $sqF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
                $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
                    $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
                        $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
                        $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
                        $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
                        $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
                        $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
                        $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
                        $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);

            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $ninetyfirstF7 = $context->builder->fmul($ninetyfirstF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF7
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $ninetyfirstF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $ninetyfirstF8Eighth = $context->builder->fmul($ninetyfirstF8Sq, $sqF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
                $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
                    $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
                        $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
                        $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
                        $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
                        $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
                        $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
                        $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
                        $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);

            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $ninetyfirstF8 = $context->builder->fmul($ninetyfirstF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF8
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $ninetyfirstF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
                $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
                    $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
                        $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
                        $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
                        $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
                        $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
                        $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
                        $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
                        $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);

            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $ninetyfirstF9 = $context->builder->fmul($ninetyfirstF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF9
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $ninetyfirstF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
                $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
                    $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
                        $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
                        $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
                        $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
                        $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
                        $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
                        $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
                        $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);

            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $ninetyfirstF10 = $context->builder->fmul($ninetyfirstF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF10
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
                $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
                    $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
                        $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
                        $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
                        $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
                        $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
                        $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
                        $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
                        $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);

            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $ninetyfirstF11 = $context->builder->fmul($ninetyfirstF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF11
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
                $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
                    $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
                        $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
                        $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
                        $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
                        $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
                        $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
                        $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
                        $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);

            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $ninetyfirstF12 = $context->builder->fmul($ninetyfirstF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF12
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
                $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
                    $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
                        $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
                        $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
                        $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
                        $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
                        $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
                        $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
                        $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);

            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $ninetyfirstF13 = $context->builder->fmul($ninetyfirstF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF13
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
                $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
                    $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
                        $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
                        $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
                        $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
                        $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
                        $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
                        $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
                        $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);

            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $ninetyfirstF14 = $context->builder->fmul($ninetyfirstF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF14
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
                $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
                    $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
                        $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
                        $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
                        $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
                        $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
                        $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
                        $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
                        $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);

            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $ninetyfirstF15 = $context->builder->fmul($ninetyfirstF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF15
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
                $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
                    $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
                        $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
                        $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
                        $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
                        $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
                        $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
                        $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
                        $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);

            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $ninetyfirstF16 = $context->builder->fmul($ninetyfirstF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF16
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
                $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
                    $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
                        $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
                        $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
                        $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
                        $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
                        $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
                        $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
                        $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);

            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $ninetyfirstF17 = $context->builder->fmul($ninetyfirstF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF17
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
                $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
                    $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
                        $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
                        $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
                        $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
                        $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
                        $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
                        $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
                        $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);

            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $ninetyfirstF18 = $context->builder->fmul($ninetyfirstF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF18
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
                $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
                    $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
                        $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
                        $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
                        $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
                        $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
                        $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
                        $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
                        $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);

            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $ninetyfirstF19 = $context->builder->fmul($ninetyfirstF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF19
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
                    $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
                        $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
                        $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
                        $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
                        $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
                        $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
                        $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
                        $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);

            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $ninetyfirstF20 = $context->builder->fmul($ninetyfirstF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF20
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
                    $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
                    $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
                    $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
                    $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
                    $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
                    $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
                    $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);

            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $ninetyfirstF21 = $context->builder->fmul($ninetyfirstF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF21
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
                $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
                $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
                $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
                $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
                $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
                $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);

            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $ninetyfirstF22 = $context->builder->fmul($ninetyfirstF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF22
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);

            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $ninetyfirstF23 = $context->builder->fmul($ninetyfirstF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF23
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);

            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $ninetyfirstF24 = $context->builder->fmul($ninetyfirstF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF24
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);

            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $ninetyfirstF25 = $context->builder->fmul($ninetyfirstF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF25
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);

            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $ninetyfirstF26 = $context->builder->fmul($ninetyfirstF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF26
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);

            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $ninetyfirstF27 = $context->builder->fmul($ninetyfirstF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF27
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);

            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $ninetyfirstF28 = $context->builder->fmul($ninetyfirstF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF28
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);

            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $ninetyfirstF29 = $context->builder->fmul($ninetyfirstF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF29
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $ninetyfirstF30 = $context->builder->fmul($ninetyfirstF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF30
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF31 = $context->builder->fmul($seventiethF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $ninetyfirstF31 = $context->builder->fmul($ninetyfirstF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF31
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $ninetyfirstF32 = $context->builder->fmul($ninetyfirstF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF32
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $ninetyfirstF33 = $context->builder->fmul($ninetyfirstF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF33
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $ninetyfirstF34 = $context->builder->fmul($ninetyfirstF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF34
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $ninetyfirstF35 = $context->builder->fmul($ninetyfirstF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF35
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $ninetyfirstF36 = $context->builder->fmul($ninetyfirstF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF36
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $ninetyfirstF37 = $context->builder->fmul($ninetyfirstF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF37
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $ninetyfirstF38 = $context->builder->fmul($ninetyfirstF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF38
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $ninetyfirstF39 = $context->builder->fmul($ninetyfirstF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF39
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $ninetyfirstF40 = $context->builder->fmul($ninetyfirstF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF40
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF41 = $context->builder->fmul($eightiethF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $ninetyfirstF41 = $context->builder->fmul($ninetyfirstF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF41
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $ninetyfirstF42 = $context->builder->fmul($ninetyfirstF42, $nF42);
            $ninetyfirstF42 = $context->builder->fmul($ninetyfirstF42, $nF42);
            $ninetyfirstF42 = $context->builder->fmul($ninetyfirstF42, $nF42);
            $ninetyfirstF42 = $context->builder->fmul($ninetyfirstF42, $nF42);
            $ninetyfirstF42 = $context->builder->fmul($ninetyfirstF42, $nF42);
            $ninetyfirstF42 = $context->builder->fmul($ninetyfirstF42, $nF42);
            $ninetyfirstF42 = $context->builder->fmul($ninetyfirstF42, $nF42);
            $ninetyfirstF42 = $context->builder->fmul($ninetyfirstF42, $nF42);
            $ninetyfirstF42 = $context->builder->fmul($ninetyfirstF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF42
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfirstF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfirstF43 = $context->builder->fmul($ninetyfirstF43, $nF43);
            $ninetyfirstF43 = $context->builder->fmul($ninetyfirstF43, $nF43);
            $ninetyfirstF43 = $context->builder->fmul($ninetyfirstF43, $nF43);
            $ninetyfirstF43 = $context->builder->fmul($ninetyfirstF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF43
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetyfirstF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetyfirstF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetyfirstF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetyfirstF44 = $context->builder->fmul($ninetyfirstF44, $nF44);
            $ninetyfirstF44 = $context->builder->fmul($ninetyfirstF44, $nF44);
            $ninetyfirstF44 = $context->builder->fmul($ninetyfirstF44, $nF44);
            $ninetyfirstF44 = $context->builder->fmul($ninetyfirstF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF44
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetyfirstF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetyfirstF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetyfirstF45 = $context->builder->fmul($ninetyfirstF45, $nF45);
            $ninetyfirstF45 = $context->builder->fmul($ninetyfirstF45, $nF45);
            $ninetyfirstF45 = $context->builder->fmul($ninetyfirstF45, $nF45);
            $ninetyfirstF45 = $context->builder->fmul($ninetyfirstF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF45
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetyfirstF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetyfirstF46 = $context->builder->fmul($ninetyfirstF46, $nF46);
            $ninetyfirstF46 = $context->builder->fmul($ninetyfirstF46, $nF46);
            $ninetyfirstF46 = $context->builder->fmul($ninetyfirstF46, $nF46);
            $ninetyfirstF46 = $context->builder->fmul($ninetyfirstF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF46
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $ninetyfirstF47 = $context->builder->fmul($ninetyfirstF47, $nF47);
            $ninetyfirstF47 = $context->builder->fmul($ninetyfirstF47, $nF47);
            $ninetyfirstF47 = $context->builder->fmul($ninetyfirstF47, $nF47);
            $ninetyfirstF47 = $context->builder->fmul($ninetyfirstF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF47
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetyfirstF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetyfirstF48 = $context->builder->fmul($ninetyfirstF48, $nF48);
            $ninetyfirstF48 = $context->builder->fmul($ninetyfirstF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF48
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $ninetyfirstF49 = $context->builder->fmul($ninetyfirstF49, $nF49);
            $ninetyfirstF49 = $context->builder->fmul($ninetyfirstF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF49
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (eightyninth)');
            }
            $eightyninthLong = JITVariable::KIND_VARIABLE === $eightyninthVar->kind
                ? $context->builder->load($eightyninthVar->value)
                : $eightyninthVar->value;
            $ov50Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyninth_ov');
            $ok50Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_eightyninth_ok');
            $context->builder->branchIf($ov50, $ov50Block, $ok50Block);

            $context->builder->positionAtEnd($ov50Block);
            $eightyninthF50 = $context->builder->load($eightyninthVar->longArithOverflowDoubleSlot);
            $nF50 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF50 = $context->builder->fmul($eightyninthF50, $nF50);
            $ninetyfirstF50 = $context->builder->fmul($ninetyfirstF50, $nF50);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF50
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
                throw new \LogicException('pow() **91 expected smul overflow metadata (ninetieth)');
            }
            $ninetiethLong = JITVariable::KIND_VARIABLE === $ninetiethVar->kind
                ? $context->builder->load($ninetiethVar->value)
                : $ninetiethVar->value;
            $ov51Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_ninetieth_ov');
            $ok51Block = BasicBlockHelper::append($context, 'pow_ninetyfirst_ninetieth_ok');
            $context->builder->branchIf($ov51, $ov51Block, $ok51Block);

            $context->builder->positionAtEnd($ov51Block);
            $ninetiethF51 = $context->builder->load($ninetiethVar->longArithOverflowDoubleSlot);
            $nF51 = $context->builder->siToFp($n, $f64);
            $ninetyfirstF51 = $context->builder->fmul($ninetiethF51, $nF51);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfirstF51
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok51Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $ninetiethLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }


        throw new \LogicException('JitPowIntegerEmitExponents80to91: unhandled expFold '.$expFold);
    }
}
