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
