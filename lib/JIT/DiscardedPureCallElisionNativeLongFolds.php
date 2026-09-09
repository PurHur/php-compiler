<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Compile-time native-long / intdiv / mul fold helpers used by typed arith
 * lowering (#36403 / #36386 / #23483).
 *
 * Extracted from {@see DiscardedPureCallElision} so the hub stays under the
 * size-budget ratchet. Bit-shift / bitwise-logic folds live in
 * {@see DiscardedPureCallElisionNativeLongBitwiseFolds} (#36387).
 * Pow / compare folds live in
 * {@see DiscardedPureCallElisionNativeLongComparePowFolds} (#36387).
 * External call sites keep using
 * {@code DiscardedPureCallElision::…} (trait methods on the hub class).
 *
 * Used via {@code use DiscardedPureCallElisionNativeLongFolds;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: Zend/zend_operators.c, ext/standard/math.c (intdiv).
 */
trait DiscardedPureCallElisionNativeLongFolds
{
    /**
     * Public for {@see NoThrowCallElision} — when true, discarded or used
     * {@code intdiv} cannot {@code DivisionByZeroError} / {@code ArithmeticError}
     * / {@code TypeError} / soft-null deprecate (#36386 / peer #37153).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function intdivArgsCannotThrow(array $callArgs): bool
    {
        return self::intdivArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Skip the LLVM zero-divisor branch when the divisor truncates to a
     * compile-time long ≠ 0 (php-src {@code Z_PARAM_LONG}).
     *
     * Also used for typed native-long {@code /} and {@code %} (zend_operators.c
     * {@code div_function} / {@code mod_function}) — same proof (#36386).
     */
    public static function intdivCanSkipZeroDivisorGuard(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && 0 !== $d;
    }

    /**
     * Skip the {@code n % -1 → 0} PHI when the divisor is a compile-time long
     * proven ≠ {@code -1} (php-src {@code mod_function}; LLVM {@code srem}
     * of {@code INT_MIN}/{-1} is poison only for {@code -1}).
     *
     * Also skips the typed native-long {@code /} {@code PHP_INT_MIN}/{-1}
     * promote arm ({@see \PHPCompiler\JIT\JitLongDiv::binaryNativeLong}).
     */
    public static function nativeLongDivisorCanSkipNegOneModuloBranch(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && -1 !== $d;
    }

    /**
     * Typed native-long {@code /} with compile-time divisor {@code 1} is identity —
     * emit the dividend (no {@code sdiv}/{@code srem}, no zero-guard, no
     * exactness/promote CFG). {@code 1 / $n} is not identity.
     *
     * php-src: Zend/zend_operators.c div_function after convert_to_long.
     * Peer {@code | 0} / {@code << 0} / {@code + 0} (#37212 / #37208 / #37200).
     */
    public static function nativeLongDivisorIsCompileTimeOne(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && 1 === $d;
    }

    /**
     * Compile-time divisor {@code -1} for {@code intdiv($n, -1)} and typed
     * {@code / -1} → {@code -n} (php-src {@code PHP_FUNCTION(intdiv)} /
     * {@code div_function}; peer typed {@code * -1} / {@code % -1} → {@code 0}).
     * Callers still emit the {@code PHP_INT_MIN} {@code ArithmeticError} (intdiv)
     * or float promote ({@code /}) unless {@see intdivCanSkipIntMinNegOneGuard}
     * proves safe.
     */
    public static function nativeLongDivisorIsCompileTimeNegOne(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && -1 === $d;
    }

    /**
     * Typed native-long {@code %} with compile-time divisor {@code ±1} is always
     * {@code 0} (php-src {@code mod_function}; {@code n % -1} and {@code n % 1}).
     * Emit constant {@code 0} without {@code srem} / zero-guard / neg-one PHI.
     */
    public static function nativeLongModuloDivisorFoldsToZero(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && (1 === $d || -1 === $d);
    }

    /**
     * Typed native-long arithmetic when both operands are the same storage /
     * SSA payload:
     * - {@code $n - $n} → {@code 0} (omit {@code sub} /
     *   {@code llvm.ssub.with.overflow}). Algebra holds for every zend_long
     *   including {@code PHP_INT_MIN} ({@code ZEND_SIGNED_SUB_OVERFLOW} is a
     *   no-op for equal operands).
     * - {@code $n + $n} → {@code shl 1} with {@code ashr} overflow (omit
     *   {@code llvm.sadd.with.overflow}; same shape as compile-time {@code * 2}).
     * - {@code $n / $n} → {@code 1} (omit {@code sdiv}/{@code srem}/exactness;
     *   callers keep {@code DivisionByZeroError} when {@code n == 0}). Equal
     *   nonzero longs always divide exactly ({@code INT_MIN}/{@code INT_MIN}
     *   is 1, not the INT_MIN/−1 promote case).
     * - {@code $n % $n} → {@code 0} (omit {@code srem} / neg-one PHI; callers
     *   keep the zero-divisor guard).
     *
     * Peer same-operand bitwise ({@see bitwiseLogicSameOperandFold}),
     * compile-time {@code - 0}/{@code + 0} identity
     * ({@see nativeLongArithIsCompileTimeIdentityOrZero}),
     * {@see nativeLongMulCompileTimePowerOfTwoShift}, and compile-time
     * {@code % ±1} / {@code / 1} ({@see nativeLongModuloDivisorFoldsToZero} /
     * {@see nativeLongDivisorIsCompileTimeOne}).
     *
     * php-src: Zend/zend_operators.c sub_function / add_function /
     * div_function / mod_function / ZEND_SIGNED_{SUB,ADD}_OVERFLOW.
     *
     * @return 'zero'|'shl1'|'one'|null fold to 0, shl×2, const 1, or null when N/A
     */
    public static function nativeLongArithSameOperandFold(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        if (!self::nativeLongOperandsAreSame($left, $right)) {
            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_MINUS === $opType
            || \PHPCompiler\OpCode::TYPE_MODULO === $opType
        ) {
            return 'zero';
        }
        if (\PHPCompiler\OpCode::TYPE_PLUS === $opType) {
            return 'shl1';
        }
        if (\PHPCompiler\OpCode::TYPE_DIV === $opType) {
            return 'one';
        }

        return null;
    }

    /**
     * {@code intdiv($n, $n)} → {@code 1} when both args are the same typed
     * native-long storage / SSA payload (peer typed {@code $n / $n}).
     *
     * Omits {@code sdiv} and the {@code PHP_INT_MIN}/{-1} {@code ArithmeticError}
     * arm (equal nonzero longs always divide exactly; {@code INT_MIN}/{@code INT_MIN}
     * is 1, not the INT_MIN/−1 case). Callers keep {@code DivisionByZeroError}
     * when {@code n == 0}.
     *
     * php-src: ext/standard/math.c {@code PHP_FUNCTION(intdiv)}.
     */
    public static function intdivSameOperandFoldsToOne(
        Variable $left,
        Variable $right
    ): bool {
        return self::nativeLongOperandsAreSame($left, $right);
    }


    /**
     * Typed native-long {@code *} with a compile-time {@code -1} operand is
     * {@code zendi_negate_function} — emit {@code negate} of the other operand
     * (no {@code llvm.smul.with.overflow}). Callers still promote
     * {@code PHP_INT_MIN} → float unless
     * {@see \PHPCompiler\JIT\JitLongArithOverflow::canSkipOverflowPromote}
     * proves safe (peer {@code intdiv($n, -1)} / unary −).
     *
     * php-src: Zend/zend_operators.c mul_function / zendi_negate_function.
     *
     * @return 'left'|'right'|null which operand to negate, or null when not {@code * -1}
     */
    public static function nativeLongMulIsCompileTimeNegOne(
        Variable $left,
        Variable $right
    ): ?string {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (-1 === $a) {
            return 'right';
        }
        if (-1 === $b) {
            return 'left';
        }

        return null;
    }

    /**
     * Typed native-long {@code *} with a compile-time positive power-of-two
     * factor {@code 2^k} ({@code k} in 1..62) may lower to {@code shl} of the
     * other operand (overflow via {@code ashr} round-trip ≠ src → float
     * promote; peer {@code * -1} / {@code * 1}).
     *
     * php-src: Zend/zend_operators.c mul_function /
     * {@code ZEND_LONG_MUL_OVERFLOW}. {@code * 1} stays
     * {@see nativeLongArithIsCompileTimeIdentityOrZero}; {@code * -1} stays
     * {@see nativeLongMulIsCompileTimeNegOne}. Negative factors and {@code 2^63}
     * (stored as {@code PHP_INT_MIN}) are not powers of two here.
     *
     * @return array{side: 'left'|'right', shift: int}|null which operand to
     *         shift and the shift count, or null when not {@code * 2^k}
     */
    public static function nativeLongMulCompileTimePowerOfTwoShift(
        Variable $left,
        Variable $right
    ): ?array {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (null !== $a) {
            $shift = self::positivePowerOfTwoShift($a);
            if (null !== $shift) {
                return ['side' => 'right', 'shift' => $shift];
            }
        }
        if (null !== $b) {
            $shift = self::positivePowerOfTwoShift($b);
            if (null !== $shift) {
                return ['side' => 'left', 'shift' => $shift];
            }
        }

        return null;
    }

    /**
     * Shift count for a positive power-of-two factor {@code 2^k} ({@code k} in
     * 1..62), or null when not applicable ({@code * 1} / negatives / non-pow2).
     */
    private static function positivePowerOfTwoShift(int $factor): ?int
    {
        if ($factor < 2) {
            return null;
        }
        if (0 !== ($factor & ($factor - 1))) {
            return null;
        }
        $shift = 0;
        $v = $factor;
        while (0 === ($v & 1)) {
            ++$shift;
            $v >>= 1;
        }

        return $shift >= 1 && $shift <= 62 ? $shift : null;
    }

    /**
     * Typed native-long {@code +}/{@code -}/{@code *} identity / zero when one
     * operand is a compile-time long that does not change the other (or forces
     * zero):
     * {@code + 0}, {@code - 0}, {@code * 1} (and mirrored {@code 0 +}/{@code 1 *}),
     * and {@code * 0} → constant {@code 0}.
     * Emit the kept operand / {@code 0} (no {@code add}/{@code sub}/{@code mul},
     * no overflow intrinsic). {@code 0 - $n} is not identity; {@code * -1} uses
     * {@see nativeLongMulIsCompileTimeNegOne} → negate.
     *
     * php-src: Zend/zend_operators.c add/sub/mul_function after convert_to_long;
     * {@code ZEND_SIGNED_*_OVERFLOW} is a no-op for these shapes.
     * Peer {@code / 1} / {@code | 0} / {@code << 0} (#37214 / #37212 / #37208).
     *
     * @return 'left'|'right'|'zero'|null which result to emit, or null when not folded
     */
    public static function nativeLongArithIsCompileTimeIdentityOrZero(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (\PHPCompiler\OpCode::TYPE_PLUS === $opType) {
            if (0 === $a) {
                return 'right';
            }
            if (0 === $b) {
                return 'left';
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_MINUS === $opType) {
            // Only right-hand 0 is identity; {@code 0 - PHP_INT_MIN} overflows.
            if (0 === $b) {
                return 'left';
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_MUL === $opType) {
            if (0 === $a || 0 === $b) {
                return 'zero';
            }
            if (1 === $a) {
                return 'right';
            }
            if (1 === $b) {
                return 'left';
            }

            return null;
        }

        return null;
    }

    /**
     * Skip the LLVM double zero-divisor branch when the divisor is a
     * compile-time numeric proven ≠ {@code 0.0} (NAN is not equal to 0 under
     * ordered compare — php-src leaves {@code / NAN} as NAN).
     */
    public static function doubleDivisorCanSkipZeroGuard(Variable $divisor): bool
    {
        $d = self::compileTimeNumericScalar($divisor);
        if (null === $d) {
            return false;
        }
        // +0.0 / -0.0 must keep DivisionByZeroError; NAN ≠ 0 → safe to skip.
        return 0.0 !== $d;
    }

    /**
     * Compile-time {@code log()} base as a float (php-src {@code Z_PARAM_DOUBLE}).
     * Null when the base is not a compile-time numeric scalar.
     */
    public static function compileTimeLogBase(Variable $base): ?float
    {
        return self::compileTimeNumericScalar($base);
    }

    /**
     * Skip {@code log()} base≤0 {@code ValueError} when the base is a
     * compile-time float {@code > 0} or {@code NAN} (php-src math.c: NAN is
     * not ≤ 0). Soft-null / runtime typed / ≤0 stay live (#36386).
     */
    public static function logBaseCanSkipValueErrorGuard(Variable $base): bool
    {
        $b = self::compileTimeNumericScalar($base);
        if (null === $b) {
            return false;
        }
        // NAN is not ≤ 0 in php-src; finite ≤0 must keep the ValueError branch.
        if ($b !== $b) {
            return true;
        }

        return $b > 0.0;
    }

    /**
     * Skip the LLVM {@code PHP_INT_MIN}/{-1} branch when the divisor cannot be
     * {@code -1}, or both operands are compile-time longs that are not that pair.
     */
    public static function intdivCanSkipIntMinNegOneGuard(Variable $dividend, Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);
        if (null === $d) {
            return false;
        }
        if (-1 !== $d) {
            return true;
        }
        $n = self::compileTimeLongScalar($dividend);

        return null !== $n && \PHP_INT_MIN !== $n;
    }
}
