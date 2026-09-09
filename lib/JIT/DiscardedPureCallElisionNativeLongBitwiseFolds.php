<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Native-long bit-shift / bitwise-logic compile-time fold helpers (#36387).
 *
 * Extracted from {@see DiscardedPureCallElisionNativeLongFolds} so the gen-0
 * spine gets another TU and the native-long fold file stays under the
 * size-budget ratchet. External call sites keep using
 * {@code DiscardedPureCallElision::…} (trait methods on the hub class).
 *
 * Used via {@code use DiscardedPureCallElisionNativeLongBitwiseFolds;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: Zend/zend_operators.c
 * {@code shift_left_function} / {@code shift_right_function} /
 * {@code bitwise_and_function} / {@code bitwise_or_function} /
 * {@code bitwise_xor_function}.
 */
trait DiscardedPureCallElisionNativeLongBitwiseFolds
{
    /**
     * Skip the LLVM negative bit-shift {@code ArithmeticError} when the count
     * truncates to a compile-time long {@code ≥ 0} (php-src
     * {@code shift_left_function} / {@code shift_right_function}; peer typed
     * {@code /}/{@code %} proven-divisor, #36386).
     */
    public static function bitShiftCountCanSkipNegativeGuard(Variable $count): bool
    {
        $c = self::compileTimeLongScalar($count);

        return null !== $c && $c >= 0;
    }

    /**
     * Typed {@code <<}/{@code >>} with compile-time count {@code 0} is identity —
     * emit the left operand (no {@code shl}/{@code ashr}, no negative-count
     * guard). Peer {@code + 0}/{@code - 0} overflow skip (#37200 / #36386).
     *
     * @see php-src Zend/zend_operators.c shift_left_function / shift_right_function
     */
    public static function bitShiftCountIsCompileTimeZero(Variable $count): bool
    {
        $c = self::compileTimeLongScalar($count);

        return null !== $c && 0 === $c;
    }

    /**
     * Typed native-long {@code &}|{@code ^} identity when one operand is a
     * compile-time long that does not change the other:
     * {@code | 0}, {@code ^ 0}, {@code & -1} (and the mirrored forms).
     * Emit the non-identity operand (no {@code and}/{@code or}/{@code xor}).
     *
     * php-src: Zend/zend_operators.c bitwise_and/or/xor_function after
     * {@code convert_to_long}. Peer shift-count {@code 0} (#37208 / #36386).
     *
     * @return 'left'|'right'|null which operand to keep, or null when not identity
     */
    public static function bitwiseLogicIsCompileTimeIdentity(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (\PHPCompiler\OpCode::TYPE_BITWISE_OR === $opType
            || \PHPCompiler\OpCode::TYPE_BITWISE_XOR === $opType
        ) {
            if (0 === $a) {
                return 'right';
            }
            if (0 === $b) {
                return 'left';
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_AND === $opType) {
            // All-bits-set mask is identity for signed zend_long (two's complement).
            if (-1 === $a) {
                return 'right';
            }
            if (-1 === $b) {
                return 'left';
            }

            return null;
        }

        return null;
    }

    /**
     * Typed native-long bitwise constant results when one operand is a
     * compile-time long that forces the outcome:
     * {@code & 0} → {@code 0}, {@code | -1} → {@code -1}, {@code ^ -1} →
     * {@code ~} of the other operand (and the mirrored forms).
     *
     * php-src: Zend/zend_operators.c bitwise_and/or/xor_function after
     * {@code convert_to_long}. Peer identity folds ({@see bitwiseLogicIsCompileTimeIdentity}).
     *
     * @return array{kind: 'zero'|'all_ones'|'not', keep: 'left'|'right'}|null
     *   {@code keep} is the surviving operand for {@code not}; unused for
     *   {@code zero}/{@code all_ones} (still names which side was non-const).
     */
    public static function bitwiseLogicIsCompileTimeConstantResult(
        int $opType,
        Variable $left,
        Variable $right
    ): ?array {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (\PHPCompiler\OpCode::TYPE_BITWISE_AND === $opType) {
            if (0 === $a) {
                return ['kind' => 'zero', 'keep' => 'right'];
            }
            if (0 === $b) {
                return ['kind' => 'zero', 'keep' => 'left'];
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_OR === $opType) {
            if (-1 === $a) {
                return ['kind' => 'all_ones', 'keep' => 'right'];
            }
            if (-1 === $b) {
                return ['kind' => 'all_ones', 'keep' => 'left'];
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_XOR === $opType) {
            // {@code n ^ -1} ≡ {@code ~n} (two's complement zend_long).
            if (-1 === $a) {
                return ['kind' => 'not', 'keep' => 'right'];
            }
            if (-1 === $b) {
                return ['kind' => 'not', 'keep' => 'left'];
            }

            return null;
        }

        return null;
    }

    /**
     * Typed native-long {@code &}|{@code ^} when both operands are the same
     * storage / SSA payload: {@code $n & $n} / {@code $n | $n} → {@code $n},
     * {@code $n ^ $n} → {@code 0} (omit {@code and}/{@code or}/{@code xor}).
     *
     * Algebra holds for any zend_long; peer compile-time {@code |0}/{@code ^0}/
     * {@code &-1} identity (#37212 / #36386) and constant folds
     * ({@see bitwiseLogicIsCompileTimeConstantResult}).
     *
     * php-src: Zend/zend_operators.c bitwise_and/or/xor_function.
     *
     * @return 'left'|'zero'|null keep left, fold to 0, or null when not same-operand
     */
    public static function bitwiseLogicSameOperandFold(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        if (!self::nativeLongOperandsAreSame($left, $right)) {
            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_AND === $opType
            || \PHPCompiler\OpCode::TYPE_BITWISE_OR === $opType
        ) {
            return 'left';
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_XOR === $opType) {
            return 'zero';
        }

        return null;
    }

}
