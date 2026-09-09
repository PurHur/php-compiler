<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MathFpow;
use PHPCompiler\JIT\Builtin\PowIntRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\DiscardedPureCallElision;
use PHPCompiler\JIT\JitEnumNumericOperandGuard;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitLongArithOverflow;
use PHPCompiler\JIT\JitPowNumericOperandGuard;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitValueNumeric;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for pow() int/float return (issue #3678, #35058). */
final class JitPow
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('pow() requires exactly two arguments');
        }

        // Zend pow_function and ** share the integer fast path (zend_operators.c).
        // TYPE_POW already set powReturnValueBox; pow() FUNCCALL must not skip it —
        // leftover float-only path made AOT var_dump(pow(2,3)) print float(8) (#33848 / #3678).
        return self::invokeBoxedIntAware($context, ...$args);
    }

    /**
     * pow() / ** — preserve int in the value box when both operands are long.
     */
    private static function invokeBoxedIntAware(Context $context, JITVariable ...$args): Value
    {
        JitPowNumericOperandGuard::guardOperands($context, $args[0], $args[1]);
        JitEnumNumericOperandGuard::guardPow($context, $args[0], $args[1]);
        if (self::needsBoxedPowLowering(...$args)) {
            if (JitValueBox::isValueOperand($args[0]) && JitValueBox::isValueOperand($args[1])) {
                return JitValueNumeric::powValueOperands($context, $args[0], $args[1]);
            }

            return self::invokeMixedBoxedPow($context, $args[0], $args[1]);
        }
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        if (self::preferIntegerPowPath(...$args)) {
            $folded = self::tryFoldCompileTimeIntegerPow($context, $slot, $slotPtr, $args[0], $args[1]);
            if (null !== $folded) {
                return $folded;
            }
            self::emitIntegerPowViaMathFpow($context, $slotPtr, $args[0], $args[1]);

            return $slotPtr;
        }

        // Runtime int vs float dispatch (numeric strings, boxed locals, floats — #35058, #35337).
        return self::invokeBoxedRuntimeDispatch($context, $slotPtr, $args[0], $args[1]);
    }

    /**
     * Runtime IS_LONG×IS_LONG → int fast path; otherwise float pow (php-src zend_operators.c).
     */
    private static function invokeBoxedRuntimeDispatch(
        Context $context,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp
    ): Value {
        PowIntRuntime::ensureLinked($context);
        MathFpow::ensureLinked($context);

        $bothIntegral = $context->builder->and(
            self::operandIsIntegralForPow($context, $base),
            self::operandIsIntegralForPow($context, $exp)
        );
        $intBlock = BasicBlockHelper::append($context, 'pow_runtime_int');
        $floatBlock = BasicBlockHelper::append($context, 'pow_runtime_float');
        $done = BasicBlockHelper::append($context, 'pow_runtime_done');
        $context->builder->branchIf($bothIntegral, $intBlock, $floatBlock);

        $context->builder->positionAtEnd($intBlock);
        self::emitIntegerPowViaMathFpow($context, $slotPtr, $base, $exp);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($floatBlock);
        $double = $context->getTypeFromString('double');
        $baseD = pow::toJitDouble($context, $base, $double);
        $expD = pow::toJitDouble($context, $exp, $double);
        $result = MathFpow::invoke($context, $baseD, $expD);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $result
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $slotPtr;
    }

    /**
     * Property/dim/by-ref boxed operands and runtime locals — {@see __phpc_pow_int} mis-reads
     * dynamic i64 from value boxes (mul/** divergence; #35978 / leftover #35984).
     */
    private static function needsBoxedPowLowering(JITVariable ...$args): bool
    {
        foreach ($args as $arg) {
            if (!JitValueBox::isValueOperand($arg)) {
                continue;
            }
            if (
                null !== $arg->objectPropertySlot
                || null !== $arg->writableHt
                || null !== $arg->valueBoxAliasPtr
                || $arg->assignRefLvalueAlias
                || $arg->borrowedValueEntry
            ) {
                return true;
            }
        }

        return JitValueBox::isValueOperand($args[0]) || JitValueBox::isValueOperand($args[1]);
    }

    /**
     * Integer ** via MathFpow — avoids broken __phpc_pow_int on boxed operands (#35978).
     *
     * Compile-time exponent {@code 0} → {@code 1}, {@code 1} → identity,
     * {@code 2} → {@code base * base}, {@code 3} → {@code base^3},
     * {@code 4} → {@code (base*base)^2}, {@code 5} → {@code base^3 * base^2},
     * {@code 6} → {@code (base^3)^2}, {@code 7} → {@code (base^3)^2 * base},
     * {@code 8} → {@code ((base*base)^2)^2}, {@code 9} →
     * {@code ((base^3)^2)*(base^3)}, {@code 10} → {@code (base^5)^2},
     * {@code 11} → {@code (base^5)^2 * base}, {@code 12} →
     * {@code (base^6)^2}, {@code 13} → {@code (base^6)^2 * base},
     * {@code 14} → {@code (base^7)^2}, {@code 15} → {@code (base^7)^2 * base},
     * {@code 16} → {@code (base^8)^2}, {@code 17} → {@code (base^8)^2 * base}
     * with chained smul overflow→float omit {@code llvm.pow.f64}
     * (#36386; php-src {@code pow_function} / {@code zend_pow} /
     * {@code mul_function}).
     */
    private static function emitIntegerPowViaMathFpow(
        Context $context,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp
    ): void {
        $expFold = DiscardedPureCallElision::nativeLongPowCompileTimeExponentFold($exp);
        if ('one' === $expFold) {
            // Incl. 0**0 → 1 (Zend pow_function).
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $slotPtr,
                $context->getTypeFromString('int64')->constInt(1, false)
            );

            return;
        }
        if ('identity' === $expFold) {
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $slotPtr,
                $context->builder->intCast($baseL, $context->getTypeFromString('int64'))
            );

            return;
        }
        if ('square' === $expFold) {
            // Same shape as typed $n*$n — overflow promotes to float (mul_function).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $baseI64 = $context->builder->intCast($baseL, $i64);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $baseI64,
                $baseI64,
                $slotPtr
            );

            return;
        }
        if ('cube' === $expFold) {
            // n*n*n: first smul n*n; on overflow finish in float; else smul ×n.
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **3 expected smul overflow metadata');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ovBlock = BasicBlockHelper::append($context, 'pow_cube_sq_ov');
            $okBlock = BasicBlockHelper::append($context, 'pow_cube_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_cube_done');
            $context->builder->branchIf($ov1, $ovBlock, $okBlock);

            $context->builder->positionAtEnd($ovBlock);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $cuF = $context->builder->fmul($sqF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $cuF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($okBlock);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('fourth' === $expFold) {
            // (n*n)*(n*n): first smul n*n; on overflow finish sqF*sqF in float;
            // else smul sq*sq (overflow→float via writeBoxedBinary).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **4 expected smul overflow metadata');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ovBlock = BasicBlockHelper::append($context, 'pow_fourth_sq_ov');
            $okBlock = BasicBlockHelper::append($context, 'pow_fourth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fourth_done');
            $context->builder->branchIf($ov1, $ovBlock, $okBlock);

            $context->builder->positionAtEnd($ovBlock);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $fourthF = $context->builder->fmul($sqF, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fourthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($okBlock);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $sqLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('fifth' === $expFold) {
            // n^5 = (n*n*n)*(n*n): sq=n*n, cu=sq*n, then cu*sq. Overflow
            // arms finish in float (sqF*sqF*nF or cuF*sqF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **5 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fifth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fifth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fifth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $fourthF = $context->builder->fmul($sqF, $sqF);
            $fifthF = $context->builder->fmul($fourthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fifthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **5 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fifth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fifth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF2 = $context->builder->fmul($cuF, $sqF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fifthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('sixth' === $expFold) {
            // n^6 = (n*n*n)*(n*n*n): sq=n*n, cu=sq*n, then cu*cu. Overflow
            // arms finish in float (sqF^3 or cuF*cuF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **6 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sixthF = $context->builder->fmul($sq2F, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **6 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sixthF2 = $context->builder->fmul($cuF, $cuF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $cuLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('seventh' === $expFold) {
            // n^7 = ((n*n*n)*(n*n*n))*n: sq=n*n, cu=sq*n, sixth=cu*cu, then
            // sixth*n. Overflow arms finish in float (sqF^3*nF, cuF^2*nF,
            // sixthF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **7 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_seventh_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_seventh_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_seventh_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq3F = $context->builder->fmul($sq2F, $sqF);
            $seventhF = $context->builder->fmul($sq3F, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $seventhF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **7 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_seventh_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_seventh_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $seventhF2 = $context->builder->fmul($cu2F, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $seventhF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **7 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_seventh_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_seventh_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $seventhF3 = $context->builder->fmul($sixthF, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $seventhF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('eighth' === $expFold) {
            // n^8 = ((n*n)*(n*n))*((n*n)*(n*n)): sq=n*n, fourth=sq*sq, then
            // fourth*fourth. Overflow arms finish in float (sqF^4 or
            // fourthF*fourthF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **8 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eighth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eighth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eighth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $eighthF = $context->builder->fmul($sq2F, $sq2F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eighthF
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
            $ov2 = $fourthVar->longArithOverflowFlag;
            if (null === $ov2 || null === $fourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **8 expected smul overflow metadata (fourth)');
            }
            $fourthLong = JITVariable::KIND_VARIABLE === $fourthVar->kind
                ? $context->builder->load($fourthVar->value)
                : $fourthVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eighth_fourth_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eighth_fourth_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $fourthF = $context->builder->load($fourthVar->longArithOverflowDoubleSlot);
            $eighthF2 = $context->builder->fmul($fourthF, $fourthF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eighthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fourthLong,
                $fourthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('ninth' === $expFold) {
            // n^9 = ((n*n*n)*(n*n*n))*(n*n*n): sq=n*n, cu=sq*n, sixth=cu*cu,
            // then sixth*cu. Overflow arms finish in float (sqF^4*nF,
            // cuF^3, sixthF*cuF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **9 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_ninth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_ninth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_ninth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $ninthF = $context->builder->fmul($sq4F, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **9 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_ninth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_ninth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $ninthF2 = $context->builder->fmul($cu2F, $cuF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **9 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_ninth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_ninth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $cuF2 = $context->builder->siToFp($cuLong, $f64);
            $ninthF3 = $context->builder->fmul($sixthF, $cuF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $cuLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('tenth' === $expFold) {
            // n^10 = ((n*n*n)*(n*n))*((n*n*n)*(n*n)): sq=n*n, cu=sq*n,
            // fifth=cu*sq, then fifth*fifth. Overflow arms finish in float
            // (sqF^5, (cuF*sqF)^2, fifthF*fifthF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **10 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_tenth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_tenth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_tenth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $tenthF = $context->builder->fmul($sq4F, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $tenthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **10 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_tenth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_tenth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $tenthF2
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
            $ov3 = $fifthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **10 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_tenth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_tenth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $tenthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('eleventh' === $expFold) {
            // n^11 = (((n*n*n)*(n*n))*((n*n*n)*(n*n)))*n: sq=n*n, cu=sq*n,
            // fifth=cu*sq, tenth=fifth*fifth, then tenth*n. Overflow arms
            // finish in float (sqF^5*nF, (cuF*sqF)^2*nF, fifthF^2*nF,
            // tenthF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **11 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eleventh_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eleventh_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eleventh_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq5F = $context->builder->fmul($sq4F, $sqF);
            $eleventhF = $context->builder->fmul($sq5F, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eleventhF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **11 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eleventh_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eleventh_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF = $context->builder->fmul($fifthF, $fifthF);
            $eleventhF2 = $context->builder->fmul($tenthF, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eleventhF2
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
            $ov3 = $fifthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **11 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_eleventh_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_eleventh_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $tenthF2 = $context->builder->fmul($fifthF2, $fifthF2);
            $eleventhF3 = $context->builder->fmul($tenthF2, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eleventhF3
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
            $ov4 = $tenthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **11 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_eleventh_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_eleventh_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF3 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eleventhF4 = $context->builder->fmul($tenthF3, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eleventhF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twelfth' === $expFold) {
            // n^12 = ((n*n*n)*(n*n*n))*((n*n*n)*(n*n*n)): sq=n*n, cu=sq*n,
            // sixth=cu*cu, then sixth*sixth. Overflow arms finish in float
            // (sqF^6, cuF^4, sixthF*sixthF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **12 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twelfth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twelfth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twelfth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $twelfthF = $context->builder->fmul($sq4F, $sq2F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twelfthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **12 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twelfth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twelfth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $twelfthF2 = $context->builder->fmul($cu2F, $cu2F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twelfthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **12 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twelfth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twelfth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $twelfthF3 = $context->builder->fmul($sixthF, $sixthF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twelfthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $sixthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('thirteenth' === $expFold) {
            // n^13 = (((n*n*n)*(n*n*n))*((n*n*n)*(n*n*n)))*n: sq=n*n, cu=sq*n,
            // sixth=cu*cu, twelfth=sixth*sixth, then twelfth*n. Overflow arms
            // finish in float (sqF^6*nF, cuF^4*nF, sixthF^2*nF, twelfthF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **13 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirteenth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirteenth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirteenth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $twelfthF = $context->builder->fmul($sq4F, $sq2F);
            $thirteenthF = $context->builder->fmul($twelfthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirteenthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **13 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirteenth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirteenth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $twelfthF2 = $context->builder->fmul($cu2F, $cu2F);
            $thirteenthF2 = $context->builder->fmul($twelfthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirteenthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **13 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirteenth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirteenth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $twelfthF3 = $context->builder->fmul($sixthF, $sixthF);
            $thirteenthF3 = $context->builder->fmul($twelfthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirteenthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $twelfthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $sixthLong
            );
            $ov4 = $twelfthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $twelfthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **13 expected smul overflow metadata (twelfth)');
            }
            $twelfthLong = JITVariable::KIND_VARIABLE === $twelfthVar->kind
                ? $context->builder->load($twelfthVar->value)
                : $twelfthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirteenth_twelfth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirteenth_twelfth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $twelfthF4 = $context->builder->load($twelfthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $thirteenthF4 = $context->builder->fmul($twelfthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirteenthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $twelfthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('fourteenth' === $expFold) {
            // n^14 = (((n*n*n)*(n*n*n))*n)*(((n*n*n)*(n*n*n))*n): sq=n*n,
            // cu=sq*n, sixth=cu*cu, seventh=sixth*n, then seventh*seventh.
            // Overflow arms finish in float (sqF^7, (cuF^2*nF)^2,
            // (sixthF*nF)^2, seventhF^2).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **14 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fourteenth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fourteenth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fourteenth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq6F = $context->builder->fmul($sq4F, $sq2F);
            $fourteenthF = $context->builder->fmul($sq6F, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fourteenthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **14 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fourteenth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fourteenth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $seventhF2 = $context->builder->fmul($cu2F, $nF2);
            $fourteenthF2 = $context->builder->fmul($seventhF2, $seventhF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fourteenthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **14 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fourteenth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fourteenth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $seventhF3 = $context->builder->fmul($sixthF, $nF3);
            $fourteenthF3 = $context->builder->fmul($seventhF3, $seventhF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fourteenthF3
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
            $ov4 = $seventhVar->longArithOverflowFlag;
            if (null === $ov4 || null === $seventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **14 expected smul overflow metadata (seventh)');
            }
            $seventhLong = JITVariable::KIND_VARIABLE === $seventhVar->kind
                ? $context->builder->load($seventhVar->value)
                : $seventhVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fourteenth_seventh_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fourteenth_seventh_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $seventhF4 = $context->builder->load($seventhVar->longArithOverflowDoubleSlot);
            $fourteenthF4 = $context->builder->fmul($seventhF4, $seventhF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fourteenthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $seventhLong,
                $seventhLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('fifteenth' === $expFold) {
            // n^15 = (((((n*n*n)*(n*n*n))*n)*(((n*n*n)*(n*n*n))*n))*n): sq=n*n,
            // cu=sq*n, sixth=cu*cu, seventh=sixth*n, fourteenth=seventh*seventh,
            // then fourteenth*n. Overflow arms finish in float (sqF^7*nF,
            // ((cuF^2)*nF)^2*nF, (sixthF*nF)^2*nF, seventhF^2*nF, fourteenthF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **15 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fifteenth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fifteenth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fifteenth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq6F = $context->builder->fmul($sq4F, $sq2F);
            $fourteenthF = $context->builder->fmul($sq6F, $sqF);
            $fifteenthF = $context->builder->fmul($fourteenthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fifteenthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **15 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fifteenth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fifteenth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $seventhF2 = $context->builder->fmul($cu2F, $nF2);
            $fourteenthF2 = $context->builder->fmul($seventhF2, $seventhF2);
            $fifteenthF2 = $context->builder->fmul($fourteenthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fifteenthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **15 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fifteenth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fifteenth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $seventhF3 = $context->builder->fmul($sixthF, $nF3);
            $fourteenthF3 = $context->builder->fmul($seventhF3, $seventhF3);
            $fifteenthF3 = $context->builder->fmul($fourteenthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fifteenthF3
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
            $ov4 = $seventhVar->longArithOverflowFlag;
            if (null === $ov4 || null === $seventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **15 expected smul overflow metadata (seventh)');
            }
            $seventhLong = JITVariable::KIND_VARIABLE === $seventhVar->kind
                ? $context->builder->load($seventhVar->value)
                : $seventhVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fifteenth_seventh_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fifteenth_seventh_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $seventhF4 = $context->builder->load($seventhVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fourteenthF4 = $context->builder->fmul($seventhF4, $seventhF4);
            $fifteenthF4 = $context->builder->fmul($fourteenthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fifteenthF4
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
            $ov5 = $fourteenthVar->longArithOverflowFlag;
            if (null === $ov5 || null === $fourteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **15 expected smul overflow metadata (fourteenth)');
            }
            $fourteenthLong = JITVariable::KIND_VARIABLE === $fourteenthVar->kind
                ? $context->builder->load($fourteenthVar->value)
                : $fourteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fifteenth_fourteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fifteenth_fourteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $fourteenthF5 = $context->builder->load($fourteenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fifteenthF5 = $context->builder->fmul($fourteenthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fifteenthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fourteenthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('sixteenth' === $expFold) {
            // n^16 = eighth*eighth: sq=n*n, fourth=sq*sq, eighth=fourth*fourth,
            // then eighth*eighth. Overflow arms finish in float (sqF^8,
            // fourthF^4, eighthF^2).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **16 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixteenth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixteenth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixteenth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sixteenthF = $context->builder->fmul($sq4F, $sq4F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixteenthF
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
            $ov2 = $fourthVar->longArithOverflowFlag;
            if (null === $ov2 || null === $fourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **16 expected smul overflow metadata (fourth)');
            }
            $fourthLong = JITVariable::KIND_VARIABLE === $fourthVar->kind
                ? $context->builder->load($fourthVar->value)
                : $fourthVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixteenth_fourth_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixteenth_fourth_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $fourthF = $context->builder->load($fourthVar->longArithOverflowDoubleSlot);
            $fourth2F = $context->builder->fmul($fourthF, $fourthF);
            $sixteenthF2 = $context->builder->fmul($fourth2F, $fourth2F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixteenthF2
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
            $ov3 = $eighthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $eighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **16 expected smul overflow metadata (eighth)');
            }
            $eighthLong = JITVariable::KIND_VARIABLE === $eighthVar->kind
                ? $context->builder->load($eighthVar->value)
                : $eighthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixteenth_eighth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixteenth_eighth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $eighthF = $context->builder->load($eighthVar->longArithOverflowDoubleSlot);
            $sixteenthF3 = $context->builder->fmul($eighthF, $eighthF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixteenthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eighthLong,
                $eighthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('seventeenth' === $expFold) {
            // n^17 = sixteenth*n: sq=n*n, fourth=sq*sq, eighth=fourth*fourth,
            // sixteenth=eighth*eighth, then sixteenth*n. Overflow arms finish
            // in float (sqF^8*nF, fourthF^4*nF, eighthF^2*nF, sixteenthF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **17 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_seventeenth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_seventeenth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_seventeenth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sixteenthF = $context->builder->fmul($sq4F, $sq4F);
            $seventeenthF = $context->builder->fmul($sixteenthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $seventeenthF
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
            $ov2 = $fourthVar->longArithOverflowFlag;
            if (null === $ov2 || null === $fourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **17 expected smul overflow metadata (fourth)');
            }
            $fourthLong = JITVariable::KIND_VARIABLE === $fourthVar->kind
                ? $context->builder->load($fourthVar->value)
                : $fourthVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_seventeenth_fourth_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_seventeenth_fourth_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $fourthF = $context->builder->load($fourthVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fourth2F = $context->builder->fmul($fourthF, $fourthF);
            $sixteenthF2 = $context->builder->fmul($fourth2F, $fourth2F);
            $seventeenthF2 = $context->builder->fmul($sixteenthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $seventeenthF2
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
            $ov3 = $eighthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $eighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **17 expected smul overflow metadata (eighth)');
            }
            $eighthLong = JITVariable::KIND_VARIABLE === $eighthVar->kind
                ? $context->builder->load($eighthVar->value)
                : $eighthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_seventeenth_eighth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_seventeenth_eighth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $eighthF = $context->builder->load($eighthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixteenthF3 = $context->builder->fmul($eighthF, $eighthF);
            $seventeenthF3 = $context->builder->fmul($sixteenthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $seventeenthF3
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
            $ov4 = $sixteenthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $sixteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **17 expected smul overflow metadata (sixteenth)');
            }
            $sixteenthLong = JITVariable::KIND_VARIABLE === $sixteenthVar->kind
                ? $context->builder->load($sixteenthVar->value)
                : $sixteenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_seventeenth_sixteenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_seventeenth_sixteenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $sixteenthF4 = $context->builder->load($sixteenthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $seventeenthF4 = $context->builder->fmul($sixteenthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $seventeenthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixteenthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('eighteenth' === $expFold) {
            // n^18 = ninth*ninth: sq=n*n, cu=sq*n, sixth=cu*cu, ninth=sixth*cu,
            // then ninth*ninth. Overflow arms finish in float (sqF^9,
            // (cuF^3)^2, (sixthF*cuF)^2, ninthF^2).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **18 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_eighteenth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_eighteenth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_eighteenth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $eighteenthF = $context->builder->fmul($sq8F, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eighteenthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **18 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_eighteenth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_eighteenth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $ninthF2 = $context->builder->fmul($cu2F, $cuF);
            $eighteenthF2 = $context->builder->fmul($ninthF2, $ninthF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eighteenthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **18 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_eighteenth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_eighteenth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $cuF3 = $context->builder->siToFp($cuLong, $f64);
            $ninthF3 = $context->builder->fmul($sixthF, $cuF3);
            $eighteenthF3 = $context->builder->fmul($ninthF3, $ninthF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eighteenthF3
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
            $ov4 = $ninthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $ninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **18 expected smul overflow metadata (ninth)');
            }
            $ninthLong = JITVariable::KIND_VARIABLE === $ninthVar->kind
                ? $context->builder->load($ninthVar->value)
                : $ninthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_eighteenth_ninth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_eighteenth_ninth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $ninthF4 = $context->builder->load($ninthVar->longArithOverflowDoubleSlot);
            $eighteenthF4 = $context->builder->fmul($ninthF4, $ninthF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $eighteenthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $ninthLong,
                $ninthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('nineteenth' === $expFold) {
            // n^19 = eighteenth*n: sq=n*n, cu=sq*n, sixth=cu*cu, ninth=sixth*cu,
            // eighteenth=ninth*ninth, then eighteenth*n. Overflow arms finish in
            // float (sqF^9*nF, ((cuF^3)^2)*nF, ((sixthF*cuF)^2)*nF, ninthF^2*nF,
            // eighteenthF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **19 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_nineteenth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_nineteenth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_nineteenth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $eighteenthF = $context->builder->fmul($sq8F, $sqF);
            $nineteenthF = $context->builder->fmul($eighteenthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $nineteenthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **19 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_nineteenth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_nineteenth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $ninthF2 = $context->builder->fmul($cu2F, $cuF);
            $eighteenthF2 = $context->builder->fmul($ninthF2, $ninthF2);
            $nineteenthF2 = $context->builder->fmul($eighteenthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $nineteenthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **19 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_nineteenth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_nineteenth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $cuF3 = $context->builder->siToFp($cuLong, $f64);
            $ninthF3 = $context->builder->fmul($sixthF, $cuF3);
            $eighteenthF3 = $context->builder->fmul($ninthF3, $ninthF3);
            $nineteenthF3 = $context->builder->fmul($eighteenthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $nineteenthF3
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
            $ov4 = $ninthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $ninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **19 expected smul overflow metadata (ninth)');
            }
            $ninthLong = JITVariable::KIND_VARIABLE === $ninthVar->kind
                ? $context->builder->load($ninthVar->value)
                : $ninthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_nineteenth_ninth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_nineteenth_ninth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $ninthF4 = $context->builder->load($ninthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eighteenthF4 = $context->builder->fmul($ninthF4, $ninthF4);
            $nineteenthF4 = $context->builder->fmul($eighteenthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $nineteenthF4
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
            $ov5 = $eighteenthVar->longArithOverflowFlag;
            if (null === $ov5 || null === $eighteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **19 expected smul overflow metadata (eighteenth)');
            }
            $eighteenthLong = JITVariable::KIND_VARIABLE === $eighteenthVar->kind
                ? $context->builder->load($eighteenthVar->value)
                : $eighteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_nineteenth_eighteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_nineteenth_eighteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $eighteenthF5 = $context->builder->load($eighteenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $nineteenthF5 = $context->builder->fmul($eighteenthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $nineteenthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eighteenthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentieth' === $expFold) {
            // n^20 = tenth*tenth: sq=n*n, cu=sq*n, fifth=cu*sq, tenth=fifth*fifth,
            // then tenth*tenth. Overflow arms finish in float (sqF^10,
            // ((cuF*sqF)^2)^2, (fifthF^2)^2, tenthF^2).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **20 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentieth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentieth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentieth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentiethF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **20 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentieth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentieth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentiethF2
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
            $ov3 = $fifthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **20 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentieth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentieth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentiethF3
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
            $ov4 = $tenthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **20 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentieth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentieth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentiethF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentyfirst' === $expFold) {
            // n^21 = twentieth*n: sq=n*n, cu=sq*n, fifth=cu*sq, tenth=fifth*fifth,
            // twentieth=tenth*tenth, then twentieth*n. Overflow arms finish in
            // float (sqF^10*nF, ((cuF*sqF)^2)^2*nF, (fifthF^2)^2*nF, tenthF^2*nF,
            // twentiethF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **21 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentyfirst_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentyfirst_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentyfirst_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $twentyfirstF = $context->builder->fmul($twentiethF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfirstF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **21 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentyfirst_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentyfirst_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $twentyfirstF2 = $context->builder->fmul($twentiethF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfirstF2
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
            $ov3 = $fifthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **21 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentyfirst_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentyfirst_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $twentyfirstF3 = $context->builder->fmul($twentiethF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfirstF3
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
            $ov4 = $tenthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **21 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentyfirst_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentyfirst_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $twentyfirstF4 = $context->builder->fmul($twentiethF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfirstF4
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **21 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_twentyfirst_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_twentyfirst_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $twentyfirstF5 = $context->builder->fmul($twentiethF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfirstF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentysecond' === $expFold) {
            // n^22 = eleventh*eleventh: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, eleventh=tenth*n, then eleventh*eleventh.
            // Overflow arms finish in float (sqF^11, ((cuF*sqF)^2*nF)^2,
            // (fifthF^2*nF)^2, (tenthF*nF)^2, eleventhF^2).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **22 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentysecond_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentysecond_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentysecond_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $sq10F = $context->builder->fmul($sq8F, $sq2F);
            $twentysecondF = $context->builder->fmul($sq10F, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysecondF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **22 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentysecond_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentysecond_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $eleventhF2 = $context->builder->fmul($tenthF2, $nF2);
            $twentysecondF2 = $context->builder->fmul($eleventhF2, $eleventhF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysecondF2
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
            $ov3 = $fifthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **22 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentysecond_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentysecond_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $eleventhF3 = $context->builder->fmul($tenthF3, $nF3);
            $twentysecondF3 = $context->builder->fmul($eleventhF3, $eleventhF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysecondF3
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
            $ov4 = $tenthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **22 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentysecond_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentysecond_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eleventhF4 = $context->builder->fmul($tenthF4, $nF4);
            $twentysecondF4 = $context->builder->fmul($eleventhF4, $eleventhF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysecondF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $eleventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $n
            );
            $ov5 = $eleventhVar->longArithOverflowFlag;
            if (null === $ov5 || null === $eleventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **22 expected smul overflow metadata (eleventh)');
            }
            $eleventhLong = JITVariable::KIND_VARIABLE === $eleventhVar->kind
                ? $context->builder->load($eleventhVar->value)
                : $eleventhVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_twentysecond_eleventh_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_twentysecond_eleventh_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $eleventhF5 = $context->builder->load($eleventhVar->longArithOverflowDoubleSlot);
            $twentysecondF5 = $context->builder->fmul($eleventhF5, $eleventhF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysecondF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eleventhLong,
                $eleventhLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentythird' === $expFold) {
            // n^23 = twentysecond*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, eleventh=tenth*n, twentysecond=eleventh*eleventh,
            // then twentysecond*n. Overflow arms finish in float (sqF^11*nF,
            // ((cuF*sqF)^2*nF)^2*nF, (fifthF^2*nF)^2*nF, (tenthF*nF)^2*nF,
            // eleventhF^2*nF, twentysecondF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **23 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentythird_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentythird_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentythird_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $sq10F = $context->builder->fmul($sq8F, $sq2F);
            $twentysecondF = $context->builder->fmul($sq10F, $sqF);
            $twentythirdF = $context->builder->fmul($twentysecondF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentythirdF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **23 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentythird_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentythird_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $eleventhF2 = $context->builder->fmul($tenthF2, $nF2);
            $twentysecondF2 = $context->builder->fmul($eleventhF2, $eleventhF2);
            $twentythirdF2 = $context->builder->fmul($twentysecondF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentythirdF2
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
            $ov3 = $fifthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **23 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentythird_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentythird_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $eleventhF3 = $context->builder->fmul($tenthF3, $nF3);
            $twentysecondF3 = $context->builder->fmul($eleventhF3, $eleventhF3);
            $twentythirdF3 = $context->builder->fmul($twentysecondF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentythirdF3
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
            $ov4 = $tenthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **23 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentythird_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentythird_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eleventhF4 = $context->builder->fmul($tenthF4, $nF4);
            $twentysecondF4 = $context->builder->fmul($eleventhF4, $eleventhF4);
            $twentythirdF4 = $context->builder->fmul($twentysecondF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentythirdF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $eleventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $n
            );
            $ov5 = $eleventhVar->longArithOverflowFlag;
            if (null === $ov5 || null === $eleventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **23 expected smul overflow metadata (eleventh)');
            }
            $eleventhLong = JITVariable::KIND_VARIABLE === $eleventhVar->kind
                ? $context->builder->load($eleventhVar->value)
                : $eleventhVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_twentythird_eleventh_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_twentythird_eleventh_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $eleventhF5 = $context->builder->load($eleventhVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $twentysecondF5 = $context->builder->fmul($eleventhF5, $eleventhF5);
            $twentythirdF5 = $context->builder->fmul($twentysecondF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentythirdF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $twentysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eleventhLong,
                $eleventhLong
            );
            $ov6 = $twentysecondVar->longArithOverflowFlag;
            if (null === $ov6 || null === $twentysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **23 expected smul overflow metadata (twentysecond)');
            }
            $twentysecondLong = JITVariable::KIND_VARIABLE === $twentysecondVar->kind
                ? $context->builder->load($twentysecondVar->value)
                : $twentysecondVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_twentythird_twentysecond_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_twentythird_twentysecond_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $twentysecondF6 = $context->builder->load($twentysecondVar->longArithOverflowDoubleSlot);
            $nF6 = $context->builder->siToFp($n, $f64);
            $twentythirdF6 = $context->builder->fmul($twentysecondF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentythirdF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $twentysecondLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentyfourth' === $expFold) {
            // n^24 = twelfth*twelfth: sq=n*n, cu=sq*n, sixth=cu*cu,
            // twelfth=sixth*sixth, then twelfth*twelfth. Overflow arms finish
            // in float (sqF^12, cuF^8, sixthF^4, twelfthF^2).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **24 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentyfourth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentyfourth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentyfourth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $twelfthF = $context->builder->fmul($sq4F, $sq2F);
            $twentyfourthF = $context->builder->fmul($twelfthF, $twelfthF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfourthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **24 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentyfourth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentyfourth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $cu4F = $context->builder->fmul($cu2F, $cu2F);
            $twentyfourthF2 = $context->builder->fmul($cu4F, $cu4F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfourthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **24 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentyfourth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentyfourth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $twelfthF3 = $context->builder->fmul($sixthF, $sixthF);
            $twentyfourthF3 = $context->builder->fmul($twelfthF3, $twelfthF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfourthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $twelfthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $sixthLong
            );
            $ov4 = $twelfthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $twelfthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **24 expected smul overflow metadata (twelfth)');
            }
            $twelfthLong = JITVariable::KIND_VARIABLE === $twelfthVar->kind
                ? $context->builder->load($twelfthVar->value)
                : $twelfthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentyfourth_twelfth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentyfourth_twelfth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $twelfthF4 = $context->builder->load($twelfthVar->longArithOverflowDoubleSlot);
            $twentyfourthF4 = $context->builder->fmul($twelfthF4, $twelfthF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfourthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $twelfthLong,
                $twelfthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentyfifth' === $expFold) {
            // n^25 = twentyfourth*n: sq=n*n, cu=sq*n, sixth=cu*cu,
            // twelfth=sixth*sixth, twentyfourth=twelfth*twelfth, then
            // twentyfourth*n. Overflow arms finish in float (sqF^12*nF,
            // cuF^8*nF, sixthF^4*nF, twelfthF^2*nF, twentyfourthF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **25 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentyfifth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentyfifth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentyfifth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $twelfthF = $context->builder->fmul($sq4F, $sq2F);
            $twentyfourthF = $context->builder->fmul($twelfthF, $twelfthF);
            $twentyfifthF = $context->builder->fmul($twentyfourthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfifthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **25 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentyfifth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentyfifth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $cu4F = $context->builder->fmul($cu2F, $cu2F);
            $twentyfourthF2 = $context->builder->fmul($cu4F, $cu4F);
            $twentyfifthF2 = $context->builder->fmul($twentyfourthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfifthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **25 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentyfifth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentyfifth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $twelfthF3 = $context->builder->fmul($sixthF, $sixthF);
            $twentyfourthF3 = $context->builder->fmul($twelfthF3, $twelfthF3);
            $twentyfifthF3 = $context->builder->fmul($twentyfourthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfifthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $twelfthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $sixthLong
            );
            $ov4 = $twelfthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $twelfthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **25 expected smul overflow metadata (twelfth)');
            }
            $twelfthLong = JITVariable::KIND_VARIABLE === $twelfthVar->kind
                ? $context->builder->load($twelfthVar->value)
                : $twelfthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentyfifth_twelfth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentyfifth_twelfth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $twelfthF4 = $context->builder->load($twelfthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $twentyfourthF4 = $context->builder->fmul($twelfthF4, $twelfthF4);
            $twentyfifthF4 = $context->builder->fmul($twentyfourthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfifthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twelfthLong,
                $twelfthLong
            );
            $ov5 = $twentyfourthVar->longArithOverflowFlag;
            if (null === $ov5 || null === $twentyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **25 expected smul overflow metadata (twentyfourth)');
            }
            $twentyfourthLong = JITVariable::KIND_VARIABLE === $twentyfourthVar->kind
                ? $context->builder->load($twentyfourthVar->value)
                : $twentyfourthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_twentyfifth_twentyfourth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_twentyfifth_twentyfourth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentyfourthF5 = $context->builder->load($twentyfourthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $twentyfifthF5 = $context->builder->fmul($twentyfourthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyfifthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $twentyfourthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentysixth' === $expFold) {
            // n^26 = thirteenth*thirteenth: sq=n*n, cu=sq*n, sixth=cu*cu,
            // twelfth=sixth*sixth, thirteenth=twelfth*n, then
            // thirteenth*thirteenth. Overflow arms finish in float (sqF^13,
            // (cuF^4*nF)^2, (sixthF^2*nF)^2, (twelfthF*nF)^2, thirteenthF^2).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **26 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentysixth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentysixth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentysixth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $sq12F = $context->builder->fmul($sq8F, $sq4F);
            $twentysixthF = $context->builder->fmul($sq12F, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysixthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **26 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentysixth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentysixth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $twelfthF2 = $context->builder->fmul($cu2F, $cu2F);
            $thirteenthF2 = $context->builder->fmul($twelfthF2, $nF2);
            $twentysixthF2 = $context->builder->fmul($thirteenthF2, $thirteenthF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysixthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **26 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentysixth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentysixth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $twelfthF3 = $context->builder->fmul($sixthF, $sixthF);
            $thirteenthF3 = $context->builder->fmul($twelfthF3, $nF3);
            $twentysixthF3 = $context->builder->fmul($thirteenthF3, $thirteenthF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysixthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $twelfthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $sixthLong
            );
            $ov4 = $twelfthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $twelfthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **26 expected smul overflow metadata (twelfth)');
            }
            $twelfthLong = JITVariable::KIND_VARIABLE === $twelfthVar->kind
                ? $context->builder->load($twelfthVar->value)
                : $twelfthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentysixth_twelfth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentysixth_twelfth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $twelfthF4 = $context->builder->load($twelfthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $thirteenthF4 = $context->builder->fmul($twelfthF4, $nF4);
            $twentysixthF4 = $context->builder->fmul($thirteenthF4, $thirteenthF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysixthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $thirteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twelfthLong,
                $n
            );
            $ov5 = $thirteenthVar->longArithOverflowFlag;
            if (null === $ov5 || null === $thirteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **26 expected smul overflow metadata (thirteenth)');
            }
            $thirteenthLong = JITVariable::KIND_VARIABLE === $thirteenthVar->kind
                ? $context->builder->load($thirteenthVar->value)
                : $thirteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_twentysixth_thirteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_twentysixth_thirteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $thirteenthF5 = $context->builder->load($thirteenthVar->longArithOverflowDoubleSlot);
            $twentysixthF5 = $context->builder->fmul($thirteenthF5, $thirteenthF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentysixthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $thirteenthLong,
                $thirteenthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentyseventh' === $expFold) {
            // n^27 = twentysixth*n: sq=n*n, cu=sq*n, sixth=cu*cu,
            // twelfth=sixth*sixth, thirteenth=twelfth*n,
            // twentysixth=thirteenth*thirteenth, then twentysixth*n.
            // Overflow arms finish in float (sqF^13*nF, (cuF^4*nF)^2*nF,
            // (sixthF^2*nF)^2*nF, (twelfthF*nF)^2*nF, thirteenthF^2*nF,
            // twentysixthF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **27 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentyseventh_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentyseventh_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentyseventh_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $sq12F = $context->builder->fmul($sq8F, $sq4F);
            $twentysixthF = $context->builder->fmul($sq12F, $sqF);
            $twentyseventhF = $context->builder->fmul($twentysixthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyseventhF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **27 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentyseventh_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentyseventh_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $twelfthF2 = $context->builder->fmul($cu2F, $cu2F);
            $thirteenthF2 = $context->builder->fmul($twelfthF2, $nF2);
            $twentysixthF2 = $context->builder->fmul($thirteenthF2, $thirteenthF2);
            $twentyseventhF2 = $context->builder->fmul($twentysixthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyseventhF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **27 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentyseventh_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentyseventh_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $twelfthF3 = $context->builder->fmul($sixthF, $sixthF);
            $thirteenthF3 = $context->builder->fmul($twelfthF3, $nF3);
            $twentysixthF3 = $context->builder->fmul($thirteenthF3, $thirteenthF3);
            $twentyseventhF3 = $context->builder->fmul($twentysixthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyseventhF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $twelfthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $sixthLong
            );
            $ov4 = $twelfthVar->longArithOverflowFlag;
            if (null === $ov4 || null === $twelfthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **27 expected smul overflow metadata (twelfth)');
            }
            $twelfthLong = JITVariable::KIND_VARIABLE === $twelfthVar->kind
                ? $context->builder->load($twelfthVar->value)
                : $twelfthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentyseventh_twelfth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentyseventh_twelfth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $twelfthF4 = $context->builder->load($twelfthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $thirteenthF4 = $context->builder->fmul($twelfthF4, $nF4);
            $twentysixthF4 = $context->builder->fmul($thirteenthF4, $thirteenthF4);
            $twentyseventhF4 = $context->builder->fmul($twentysixthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyseventhF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $thirteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twelfthLong,
                $n
            );
            $ov5 = $thirteenthVar->longArithOverflowFlag;
            if (null === $ov5 || null === $thirteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **27 expected smul overflow metadata (thirteenth)');
            }
            $thirteenthLong = JITVariable::KIND_VARIABLE === $thirteenthVar->kind
                ? $context->builder->load($thirteenthVar->value)
                : $thirteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_twentyseventh_thirteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_twentyseventh_thirteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $thirteenthF5 = $context->builder->load($thirteenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $twentysixthF5 = $context->builder->fmul($thirteenthF5, $thirteenthF5);
            $twentyseventhF5 = $context->builder->fmul($twentysixthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyseventhF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $twentysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $thirteenthLong,
                $thirteenthLong
            );
            $ov6 = $twentysixthVar->longArithOverflowFlag;
            if (null === $ov6 || null === $twentysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **27 expected smul overflow metadata (twentysixth)');
            }
            $twentysixthLong = JITVariable::KIND_VARIABLE === $twentysixthVar->kind
                ? $context->builder->load($twentysixthVar->value)
                : $twentysixthVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_twentyseventh_twentysixth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_twentyseventh_twentysixth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $twentysixthF6 = $context->builder->load($twentysixthVar->longArithOverflowDoubleSlot);
            $nF6 = $context->builder->siToFp($n, $f64);
            $twentyseventhF6 = $context->builder->fmul($twentysixthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyseventhF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $twentysixthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentyeighth' === $expFold) {
            // n^28 = fourteenth*fourteenth: sq=n*n, cu=sq*n, sixth=cu*cu,
            // seventh=sixth*n, fourteenth=seventh*seventh, then
            // fourteenth*fourteenth. Overflow arms finish in float (sqF^14,
            // ((cuF^2)*nF)^4, (sixthF*nF)^4, seventhF^4, fourteenthF^2).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **28 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentyeighth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentyeighth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentyeighth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq6F = $context->builder->fmul($sq4F, $sq2F);
            $fourteenthF = $context->builder->fmul($sq6F, $sqF);
            $twentyeighthF = $context->builder->fmul($fourteenthF, $fourteenthF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyeighthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **28 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentyeighth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentyeighth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $seventhF2 = $context->builder->fmul($cu2F, $nF2);
            $fourteenthF2 = $context->builder->fmul($seventhF2, $seventhF2);
            $twentyeighthF2 = $context->builder->fmul($fourteenthF2, $fourteenthF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyeighthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **28 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentyeighth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentyeighth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $seventhF3 = $context->builder->fmul($sixthF, $nF3);
            $fourteenthF3 = $context->builder->fmul($seventhF3, $seventhF3);
            $twentyeighthF3 = $context->builder->fmul($fourteenthF3, $fourteenthF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyeighthF3
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
            $ov4 = $seventhVar->longArithOverflowFlag;
            if (null === $ov4 || null === $seventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **28 expected smul overflow metadata (seventh)');
            }
            $seventhLong = JITVariable::KIND_VARIABLE === $seventhVar->kind
                ? $context->builder->load($seventhVar->value)
                : $seventhVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentyeighth_seventh_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentyeighth_seventh_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $seventhF4 = $context->builder->load($seventhVar->longArithOverflowDoubleSlot);
            $fourteenthF4 = $context->builder->fmul($seventhF4, $seventhF4);
            $twentyeighthF4 = $context->builder->fmul($fourteenthF4, $fourteenthF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyeighthF4
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
            $ov5 = $fourteenthVar->longArithOverflowFlag;
            if (null === $ov5 || null === $fourteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **28 expected smul overflow metadata (fourteenth)');
            }
            $fourteenthLong = JITVariable::KIND_VARIABLE === $fourteenthVar->kind
                ? $context->builder->load($fourteenthVar->value)
                : $fourteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_twentyeighth_fourteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_twentyeighth_fourteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $fourteenthF5 = $context->builder->load($fourteenthVar->longArithOverflowDoubleSlot);
            $twentyeighthF5 = $context->builder->fmul($fourteenthF5, $fourteenthF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyeighthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fourteenthLong,
                $fourteenthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }
        if ('twentyninth' === $expFold) {
            // n^29 = twentyeighth*n: sq=n*n, cu=sq*n, sixth=cu*cu,
            // seventh=sixth*n, fourteenth=seventh*seventh,
            // twentyeighth=fourteenth*fourteenth, then twentyeighth*n.
            // Overflow arms finish in float (sqF^14*nF, ((cuF^2)*nF)^4*nF,
            // (sixthF*nF)^4*nF, seventhF^4*nF, fourteenthF^2*nF,
            // twentyeighthF*nF).
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
            $ov1 = $sqVar->longArithOverflowFlag;
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **29 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_twentyninth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_twentyninth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_twentyninth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq6F = $context->builder->fmul($sq4F, $sq2F);
            $fourteenthF = $context->builder->fmul($sq6F, $sqF);
            $twentyeighthF = $context->builder->fmul($fourteenthF, $fourteenthF);
            $twentyninthF = $context->builder->fmul($twentyeighthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyninthF
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
            $ov2 = $cuVar->longArithOverflowFlag;
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **29 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_twentyninth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_twentyninth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $seventhF2 = $context->builder->fmul($cu2F, $nF2);
            $fourteenthF2 = $context->builder->fmul($seventhF2, $seventhF2);
            $twentyeighthF2 = $context->builder->fmul($fourteenthF2, $fourteenthF2);
            $twentyninthF2 = $context->builder->fmul($twentyeighthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyninthF2
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
            $ov3 = $sixthVar->longArithOverflowFlag;
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **29 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_twentyninth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_twentyninth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $seventhF3 = $context->builder->fmul($sixthF, $nF3);
            $fourteenthF3 = $context->builder->fmul($seventhF3, $seventhF3);
            $twentyeighthF3 = $context->builder->fmul($fourteenthF3, $fourteenthF3);
            $twentyninthF3 = $context->builder->fmul($twentyeighthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyninthF3
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
            $ov4 = $seventhVar->longArithOverflowFlag;
            if (null === $ov4 || null === $seventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **29 expected smul overflow metadata (seventh)');
            }
            $seventhLong = JITVariable::KIND_VARIABLE === $seventhVar->kind
                ? $context->builder->load($seventhVar->value)
                : $seventhVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_twentyninth_seventh_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_twentyninth_seventh_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $seventhF4 = $context->builder->load($seventhVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fourteenthF4 = $context->builder->fmul($seventhF4, $seventhF4);
            $twentyeighthF4 = $context->builder->fmul($fourteenthF4, $fourteenthF4);
            $twentyninthF4 = $context->builder->fmul($twentyeighthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyninthF4
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
            $ov5 = $fourteenthVar->longArithOverflowFlag;
            if (null === $ov5 || null === $fourteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **29 expected smul overflow metadata (fourteenth)');
            }
            $fourteenthLong = JITVariable::KIND_VARIABLE === $fourteenthVar->kind
                ? $context->builder->load($fourteenthVar->value)
                : $fourteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_twentyninth_fourteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_twentyninth_fourteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $fourteenthF5 = $context->builder->load($fourteenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $twentyeighthF5 = $context->builder->fmul($fourteenthF5, $fourteenthF5);
            $twentyninthF5 = $context->builder->fmul($twentyeighthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyninthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $twentyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fourteenthLong,
                $fourteenthLong
            );
            $ov6 = $twentyeighthVar->longArithOverflowFlag;
            if (null === $ov6 || null === $twentyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **29 expected smul overflow metadata (twentyeighth)');
            }
            $twentyeighthLong = JITVariable::KIND_VARIABLE === $twentyeighthVar->kind
                ? $context->builder->load($twentyeighthVar->value)
                : $twentyeighthVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_twentyninth_twentyeighth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_twentyninth_twentyeighth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $twentyeighthF6 = $context->builder->load($twentyeighthVar->longArithOverflowDoubleSlot);
            $nF6 = $context->builder->siToFp($n, $f64);
            $twentyninthF6 = $context->builder->fmul($twentyeighthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $twentyninthF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $twentyeighthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $sixthVar->longArithOverflowFlag;
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
            $ov4 = $seventhVar->longArithOverflowFlag;
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
            $ov5 = $fourteenthVar->longArithOverflowFlag;
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
            $ov6 = $fifteenthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $sixthVar->longArithOverflowFlag;
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
            $ov4 = $seventhVar->longArithOverflowFlag;
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
            $ov5 = $fourteenthVar->longArithOverflowFlag;
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
            $ov6 = $fifteenthVar->longArithOverflowFlag;
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
            $ov7 = $thirtiethVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $fourthVar->longArithOverflowFlag;
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
            $ov3 = $eighthVar->longArithOverflowFlag;
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
            $ov4 = $sixteenthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $fourthVar->longArithOverflowFlag;
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
            $ov3 = $eighthVar->longArithOverflowFlag;
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
            $ov4 = $sixteenthVar->longArithOverflowFlag;
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
            $ov5 = $thirtysecondVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $fourthVar->longArithOverflowFlag;
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
            $ov3 = $eighthVar->longArithOverflowFlag;
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
            $ov4 = $sixteenthVar->longArithOverflowFlag;
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
            $ov5 = $seventeenthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $fourthVar->longArithOverflowFlag;
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
            $ov3 = $eighthVar->longArithOverflowFlag;
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
            $ov4 = $sixteenthVar->longArithOverflowFlag;
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
            $ov5 = $seventeenthVar->longArithOverflowFlag;
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
            $ov6 = $thirtyfourthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $sixthVar->longArithOverflowFlag;
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
            $ov4 = $ninthVar->longArithOverflowFlag;
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
            $ov5 = $eighteenthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $sixthVar->longArithOverflowFlag;
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
            $ov4 = $ninthVar->longArithOverflowFlag;
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
            $ov5 = $eighteenthVar->longArithOverflowFlag;
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
            $ov6 = $thirtysixthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $sixthVar->longArithOverflowFlag;
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
            $ov4 = $ninthVar->longArithOverflowFlag;
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
            $ov5 = $eighteenthVar->longArithOverflowFlag;
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
            $ov6 = $thirtysixthVar->longArithOverflowFlag;
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
            $ov7 = $thirtyseventhVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $sixthVar->longArithOverflowFlag;
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
            $ov4 = $ninthVar->longArithOverflowFlag;
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
            $ov5 = $eighteenthVar->longArithOverflowFlag;
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
            $ov6 = $thirtysixthVar->longArithOverflowFlag;
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
            $ov7 = $thirtyseventhVar->longArithOverflowFlag;
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
            $ov8 = $thirtyeighthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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
            $ov10 = $fortyeighthVar->longArithOverflowFlag;
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

            return;
        }


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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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
            $ov10 = $fortyeighthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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
            $ov10 = $fortyeighthVar->longArithOverflowFlag;
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
            $ov11 = $fiftiethVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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
            $ov10 = $fortyeighthVar->longArithOverflowFlag;
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
            $ov11 = $fiftiethVar->longArithOverflowFlag;
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
            $ov12 = $fiftyfirstVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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
            $ov10 = $fortyeighthVar->longArithOverflowFlag;
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
            $ov11 = $fiftiethVar->longArithOverflowFlag;
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
            $ov12 = $fiftyfirstVar->longArithOverflowFlag;
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
            $ov13 = $fiftysecondVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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
            $ov10 = $fortyeighthVar->longArithOverflowFlag;
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
            $ov11 = $fiftiethVar->longArithOverflowFlag;
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
            $ov12 = $fiftyfirstVar->longArithOverflowFlag;
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
            $ov13 = $fiftysecondVar->longArithOverflowFlag;
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
            $ov14 = $fiftythirdVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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
            $ov10 = $fortyeighthVar->longArithOverflowFlag;
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
            $ov11 = $fiftiethVar->longArithOverflowFlag;
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
            $ov12 = $fiftyfirstVar->longArithOverflowFlag;
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
            $ov13 = $fiftysecondVar->longArithOverflowFlag;
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
            $ov14 = $fiftythirdVar->longArithOverflowFlag;
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
            $ov15 = $fiftyfourthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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
            $ov10 = $fortyeighthVar->longArithOverflowFlag;
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
            $ov11 = $fiftiethVar->longArithOverflowFlag;
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
            $ov12 = $fiftyfirstVar->longArithOverflowFlag;
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
            $ov13 = $fiftysecondVar->longArithOverflowFlag;
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
            $ov14 = $fiftythirdVar->longArithOverflowFlag;
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
            $ov15 = $fiftyfourthVar->longArithOverflowFlag;
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
            $ov16 = $fiftyfifthVar->longArithOverflowFlag;
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

            return;
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
            $ov1 = $sqVar->longArithOverflowFlag;
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
            $ov2 = $cuVar->longArithOverflowFlag;
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
            $ov3 = $fifthVar->longArithOverflowFlag;
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
            $ov4 = $tenthVar->longArithOverflowFlag;
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
            $ov5 = $twentiethVar->longArithOverflowFlag;
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
            $ov6 = $fortiethVar->longArithOverflowFlag;
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
            $ov7 = $fortysecondVar->longArithOverflowFlag;
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
            $ov8 = $fortyfourthVar->longArithOverflowFlag;
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
            $ov9 = $fortysixthVar->longArithOverflowFlag;
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
            $ov10 = $fortyeighthVar->longArithOverflowFlag;
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
            $ov11 = $fiftiethVar->longArithOverflowFlag;
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
            $ov12 = $fiftyfirstVar->longArithOverflowFlag;
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
            $ov13 = $fiftysecondVar->longArithOverflowFlag;
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
            $ov14 = $fiftythirdVar->longArithOverflowFlag;
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
            $ov15 = $fiftyfourthVar->longArithOverflowFlag;
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
            $ov16 = $fiftyfifthVar->longArithOverflowFlag;
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
            $ov17 = $fiftysixthVar->longArithOverflowFlag;
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

            return;
        }



        MathFpow::ensureLinked($context);
        $baseL = JitLongArg::lower($context, $base, 'pow() base');
        $expL = JitLongArg::lower($context, $exp, 'pow() exponent');
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $baseD = $context->builder->siToFp($baseL, $double);
        $expD = $context->builder->siToFp($expL, $double);
        $fres = MathFpow::invoke($context, $baseD, $expD);
        $longRes = $context->builder->fpToSi($fres, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $longRes
        );
    }

    /** Property/native mix — never valuePtrFromVariable the native long literal (#35978). */
    private static function invokeMixedBoxedPow(
        Context $context,
        JITVariable $base,
        JITVariable $exp
    ): Value {
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        PowIntRuntime::ensureLinked($context);
        MathFpow::ensureLinked($context);

        $boxed = JitValueBox::isValueOperand($base) ? $base : $exp;
        $other = $boxed === $base ? $exp : $base;
        $boxedTy = JitValueNumeric::valueIsDouble($context, $boxed);
        $i1 = $context->getTypeFromString('int1');
        $otherIsDouble = JITVariable::TYPE_NATIVE_DOUBLE === $other->type
            ? $i1->constInt(1, false)
            : $i1->constInt(0, false);
        $needsFloat = $context->builder->or($boxedTy, $otherIsDouble);
        $intBlock = BasicBlockHelper::append($context, 'pow_mixed_int');
        $floatBlock = BasicBlockHelper::append($context, 'pow_mixed_float');
        $done = BasicBlockHelper::append($context, 'pow_mixed_done');
        $context->builder->branchIf($needsFloat, $floatBlock, $intBlock);

        $context->builder->positionAtEnd($intBlock);
        self::emitIntegerPowViaMathFpow($context, $slotPtr, $base, $exp);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($floatBlock);
        $double = $context->getTypeFromString('double');
        $baseD = pow::toJitDouble($context, $base, $double);
        $expD = pow::toJitDouble($context, $exp, $double);
        $result = MathFpow::invoke($context, $baseD, $expD);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $result
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $slotPtr;
    }

    private static function valueIsNativeLong(Context $context, JITVariable $boxed): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
    }

    /**
     * Zend pow_function integer fast path — operand coerces to int, not float (#35337).
     */
    private static function operandIsIntegralForPow(Context $context, JITVariable $arg): Value
    {
        $i1 = $context->getTypeFromString('int1');
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $i1->constInt(1, false);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $i1->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type || JITVariable::TYPE_NULL === $arg->type) {
            return $i1->constInt(1, false);
        }
        if (JITVariable::TYPE_STRING === $arg->type && null !== $arg->compileTimeString) {
            return $i1->constInt(
                \PHPCompiler\VM\Variable::isIntegralNumericString($arg->compileTimeString) ? 1 : 0,
                false
            );
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return self::nativeStringIsIntegralForPow($context, $arg);
        }
        if (
            null !== $arg->compileTimeLong
            && null === $arg->compileTimeFloat
            && !JitValueBox::isValueOperand($arg)
        ) {
            return $i1->constInt(1, false);
        }
        if (!JitValueBox::isValueOperand($arg)) {
            return $i1->constInt(0, false);
        }

        $isLong = self::valueIsNativeLong($context, $arg);
        $isBool = JitValueNumeric::valueIsBool($context, $arg);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        $stringIntegral = self::boxedStringIsIntegralForPow($context, $arg);

        return $context->builder->or(
            $isLong,
            $context->builder->or(
                $isBool,
                $context->builder->or($isNull, $stringIntegral)
            )
        );
    }

    /** i1: 1 when boxed operand is an integral numeric string, else 0. */
    private static function boxedStringIsIntegralForPow(Context $context, JITVariable $boxed): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $isString = JitValueNumeric::valueIsString($context, $boxed);
        $entryEnd = $context->builder->getInsertBlock();
        $strBlock = BasicBlockHelper::append($context, 'pow_str_integral');
        $done = BasicBlockHelper::append($context, 'pow_str_integral_done');
        $context->builder->branchIf($isString, $strBlock, $done);

        $context->builder->positionAtEnd($strBlock);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $strOk = self::stringStructIsIntegralForPow($context, $strPtr);
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1, 'pow_str_integral_phi');
        $phi->addIncoming($falseVal, $entryEnd);
        $phi->addIncoming($strOk, $strEnd);

        return $phi;
    }

    /** i1: 1 when a native __string__* operand is an integral numeric string. */
    private static function nativeStringIsIntegralForPow(Context $context, JITVariable $strVar): Value
    {
        $strPtr = $context->helper->loadValue($strVar);

        return self::stringStructIsIntegralForPow($context, $strPtr);
    }

    /**
     * Zend _is_numeric_string_ex IS_LONG shape for pow — no '.' or exponent (#35337).
     *
     * Matches {@see \PHPCompiler\VM\Variable::isIntegralNumericString} for common cases
     * (e.g. "2" vs "2.5"); overflow digit strings fall through to float via strtod mismatch.
     */
    private static function stringStructIsIntegralForPow(Context $context, Value $strPtr): Value
    {
        $i8ptr = self::stringDataPtr($context, $strPtr);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $null = $i8ptr->typeOf()->constNull();
        $hasDot = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call(
                $context->lookupFunction('strchr'),
                $i8ptr,
                $i32->constInt(ord('.'), false)
            ),
            $null
        );
        $hasExp = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->call(
                    $context->lookupFunction('strchr'),
                    $i8ptr,
                    $i32->constInt(ord('e'), false)
                ),
                $null
            ),
            $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->call(
                    $context->lookupFunction('strchr'),
                    $i8ptr,
                    $i32->constInt(ord('E'), false)
                ),
                $null
            )
        );
        $hasFractionalSyntax = $context->builder->or($hasDot, $hasExp);

        return $context->builder->xor($hasFractionalSyntax, $i1->constInt(1, false));
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    /**
     * Both operands compile-time ints — Zend `**` at emit time (#31966).
     */
    private static function tryFoldCompileTimeIntegerPow(
        Context $context,
        Value $slot,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp
    ): ?Value {
        $baseLong = self::compileTimeIntegralLong($base);
        $expLong = self::compileTimeIntegralLong($exp);
        if (null === $baseLong || null === $expLong) {
            return null;
        }
        if (null !== $base->compileTimeFloat || null !== $exp->compileTimeFloat) {
            return null;
        }
        $result = $baseLong ** $expLong;
        if (\is_int($result)) {
            JitValueBox::writeLong($context, $slot, $context->constantFromInteger($result));
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $context->constantFromFloat((float) $result)
            );
        }

        return $slotPtr;
    }

    /**
     * Zend pow_function / ** : integer fast path when both operands are integral.
     * Includes integer-shaped numeric strings ({@code "2"**3} → int(8)); float-shaped
     * strings ({@code "2.5"}) stay on the float path (#35344, peer #35337).
     * Boxed TYPE_VALUE may hold floats — do not truncate via JitLongArg (#35058).
     */
    private static function preferIntegerPowPath(JITVariable $base, JITVariable $exp): bool
    {
        return self::isIntegerPowOperand($base) && self::isIntegerPowOperand($exp);
    }

    private static function isIntegerPowOperand(JITVariable $operand): bool
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $operand->type
            || null !== $operand->compileTimeFloat) {
            return false;
        }
        if (JITVariable::TYPE_OBJECT === $operand->type
            || JITVariable::TYPE_HASHTABLE === $operand->type) {
            return false;
        }
        if (
            null !== $operand->compileTimeLong
            && JitValueBox::isValueOperand($operand)
        ) {
            return false;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $operand->type
            || JITVariable::TYPE_NATIVE_BOOL === $operand->type
            || null !== $operand->compileTimeLong) {
            return true;
        }
        // Compile-time integer numeric string — zend converts to IS_LONG before pow (#35344).
        if (null !== $operand->compileTimeString) {
            return \PHPCompiler\VM\Variable::isIntegralNumericString($operand->compileTimeString);
        }

        return false;
    }

    /** Compile-time long for fold — includes integral numeric strings (#35344). */
    private static function compileTimeIntegralLong(JITVariable $operand): ?int
    {
        if (
            null !== $operand->compileTimeLong
            && JitValueBox::isValueOperand($operand)
        ) {
            return null;
        }
        if (null !== $operand->compileTimeLong) {
            return $operand->compileTimeLong;
        }
        if (null !== $operand->compileTimeString
            && \PHPCompiler\VM\Variable::isIntegralNumericString($operand->compileTimeString)
        ) {
            return (int) $operand->compileTimeString;
        }

        return null;
    }
}
