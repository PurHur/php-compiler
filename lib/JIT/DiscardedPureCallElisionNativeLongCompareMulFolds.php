<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Native-long compare / mul / identity compile-time fold helpers (#36387).
 *
 * Extracted from {@see DiscardedPureCallElisionNativeLongFolds} so the gen-0
 * spine gets another TU and the native-long fold file stays under the
 * size-budget ratchet. External call sites keep using
 * {@code DiscardedPureCallElision::…} (trait methods on the hub class).
 *
 * Used via {@code use DiscardedPureCallElisionNativeLongCompareMulFolds;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: Zend/zend_operators.c
 * {@code compare_function} / {@code is_identical_function} /
 * {@code is_equal_function} / {@code zend_compare_longs} /
 * {@code mul_function} / {@code zendi_negate_function} /
 * {@code add_function} / {@code sub_function} /
 * {@code ZEND_LONG_MUL_OVERFLOW} / {@code ZEND_SIGNED_*_OVERFLOW}.
 */
trait DiscardedPureCallElisionNativeLongCompareMulFolds
{
    /**
     * Typed native-long relational / equality / spaceship when both operands
     * are the same storage / SSA payload:
     * - {@code $n === $n} / {@code $n == $n} → {@code true}
     * - {@code $n !== $n} / {@code $n != $n} → {@code false}
     * - {@code $n < $n} / {@code $n > $n} → {@code false}
     * - {@code $n <= $n} / {@code $n >= $n} → {@code true}
     * - {@code $n <=> $n} → {@code 0}
     *
     * Omits {@code icmp} and the resource-identity equal CFG
     * ({@see \PHPCompiler\JIT\JitValueCompare::nativeLongEqualWithResourceIdentity}).
     * Same-handle resource {@code ===} is still true (left ≡ right).
     *
     * Peer same-operand arith/bitwise ({@see nativeLongArithSameOperandFold} /
     * {@see bitwiseLogicSameOperandFold}).
     *
     * php-src: Zend/zend_operators.c compare_function /
     * is_identical_function / is_equal_function / zend_compare_longs.
     *
     * @return 'true'|'false'|'zero'|null const bool, spaceship 0, or null when N/A
     */
    public static function nativeLongCompareSameOperandFold(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        if (!self::nativeLongOperandsAreSame($left, $right)) {
            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_IDENTICAL === $opType
            || \PHPCompiler\OpCode::TYPE_EQUAL === $opType
            || \PHPCompiler\OpCode::TYPE_SMALLER_OR_EQUAL === $opType
            || \PHPCompiler\OpCode::TYPE_GREATER_OR_EQUAL === $opType
        ) {
            return 'true';
        }
        if (\PHPCompiler\OpCode::TYPE_NOT_IDENTICAL === $opType
            || \PHPCompiler\OpCode::TYPE_NOT_EQUAL === $opType
            || \PHPCompiler\OpCode::TYPE_SMALLER === $opType
            || \PHPCompiler\OpCode::TYPE_GREATER === $opType
        ) {
            return 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_SPACESHIP === $opType) {
            return 'zero';
        }

        return null;
    }

    /**
     * Typed native-long relational / equality / spaceship when both operands
     * are compile-time longs (distinct SSA temps / literals that
     * {@see nativeLongCompareSameOperandFold} misses because Value wrappers
     * differ):
     * - {@code 7 === 7} / {@code 7 == 7} / {@code 3 <= 7} / {@code 7 >= 3} → true
     * - {@code 7 === 3} / {@code 7 != 3} / {@code 7 < 3} / {@code 3 > 7} → false
     * - {@code 7 <=> 3} → {@code 1}, {@code 3 <=> 7} → {@code -1},
     *   {@code 7 <=> 7} → {@code 0}
     *
     * Omits {@code icmp} and the resource-identity equal CFG
     * ({@see \PHPCompiler\JIT\JitValueCompare::nativeLongEqualWithResourceIdentity}).
     *
     * Peer same-operand compare ({@see nativeLongCompareSameOperandFold}) /
     * compile-time arith ({@see \PHPCompiler\JIT\JitLongArithOverflow::tryFoldBinary}).
     *
     * php-src: Zend/zend_operators.c compare_function /
     * is_identical_function / is_equal_function / zend_compare_longs.
     *
     * @return 'true'|'false'|int|null  int is spaceship −1|0|1
     */
    public static function nativeLongCompareCompileTimeFold(
        int $opType,
        Variable $left,
        Variable $right
    ): string|int|null {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (null === $a || null === $b) {
            return null;
        }
        $cmp = $a <=> $b;
        if (\PHPCompiler\OpCode::TYPE_SPACESHIP === $opType) {
            return $cmp;
        }
        if (\PHPCompiler\OpCode::TYPE_IDENTICAL === $opType
            || \PHPCompiler\OpCode::TYPE_EQUAL === $opType
        ) {
            return 0 === $cmp ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_NOT_IDENTICAL === $opType
            || \PHPCompiler\OpCode::TYPE_NOT_EQUAL === $opType
        ) {
            return 0 !== $cmp ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_SMALLER === $opType) {
            return $cmp < 0 ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_GREATER === $opType) {
            return $cmp > 0 ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_SMALLER_OR_EQUAL === $opType) {
            return $cmp <= 0 ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_GREATER_OR_EQUAL === $opType) {
            return $cmp >= 0 ? 'true' : 'false';
        }

        return null;
    }

    /**
     * Same-operand or both-compile-time typed native-long compare fold.
     *
     * @return 'true'|'false'|int|null  int is spaceship −1|0|1
     */
    public static function nativeLongCompareFold(
        int $opType,
        Variable $left,
        Variable $right
    ): string|int|null {
        $same = self::nativeLongCompareSameOperandFold($opType, $left, $right);
        if (null !== $same) {
            return 'zero' === $same ? 0 : $same;
        }

        return self::nativeLongCompareCompileTimeFold($opType, $left, $right);
    }

    /**
     * True when both operands are typed native-long and name the same alloca or
     * SSA value (two Operand wrappers → one payload).
     */
    private static function nativeLongOperandsAreSame(Variable $left, Variable $right): bool
    {
        if (Variable::TYPE_NATIVE_LONG !== $left->type || $left->type !== $right->type) {
            return false;
        }
        if ($left === $right) {
            return true;
        }

        return $left->value === $right->value;
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
}
