<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Compile-time native-long / intdiv / bitwise / pow / compare fold helpers
 * used by typed arith lowering (#36403 / #36386 / #23483).
 *
 * Extracted from {@see DiscardedPureCallElision} so the hub stays under the
 * size-budget ratchet. External call sites keep using
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
     * Typed integer {@code **} / {@code pow()} when the exponent is a
     * compile-time long:
     * - {@code $n ** 0} / {@code pow($n, 0)} → {@code 1} (incl. {@code 0 ** 0})
     * - {@code $n ** 1} / {@code pow($n, 1)} → {@code $n} (identity)
     * - {@code $n ** 2} / {@code pow($n, 2)} → {@code $n * $n} with
     *   {@code llvm.smul.with.overflow} → float promote on overflow (same
     *   shape as typed {@code *} / {@code mul_function})
     * - {@code $n ** 3} / {@code pow($n, 3)} → {@code $n * $n * $n} with
     *   chained smul overflow→float (first {@code n*n}, then {@code ×n})
     * - {@code $n ** 4} / {@code pow($n, 4)} → {@code ($n*$n)*($n*$n)} with
     *   chained smul overflow→float (square, then square-of-square)
     * - {@code $n ** 5} / {@code pow($n, 5)} → {@code ($n*$n*$n)*($n*$n)} with
     *   chained smul overflow→float (cube × square)
     * - {@code $n ** 6} / {@code pow($n, 6)} → {@code ($n*$n*$n)*($n*$n*$n)} with
     *   chained smul overflow→float (cube × cube)
     * - {@code $n ** 7} / {@code pow($n, 7)} → {@code (($n*$n*$n)*($n*$n*$n))*$n}
     *   with chained smul overflow→float (cube × cube × n)
     * - {@code $n ** 8} / {@code pow($n, 8)} → {@code (($n*$n)*($n*$n))*(($n*$n)*($n*$n))}
     *   with chained smul overflow→float (fourth × fourth)
     * - {@code $n ** 9} / {@code pow($n, 9)} → {@code (($n*$n*$n)*($n*$n*$n))*($n*$n*$n)}
     *   with chained smul overflow→float (sixth × cube)
     * - {@code $n ** 10} / {@code pow($n, 10)} → {@code (($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n))}
     *   with chained smul overflow→float (fifth × fifth)
     * - {@code $n ** 11} / {@code pow($n, 11)} → {@code ((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n}
     *   with chained smul overflow→float (tenth × n)
     * - {@code $n ** 12} / {@code pow($n, 12)} → {@code (($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n))}
     *   with chained smul overflow→float (sixth × sixth)
     * - {@code $n ** 13} / {@code pow($n, 13)} → {@code ((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n}
     *   with chained smul overflow→float (twelfth × n)
     * - {@code $n ** 14} / {@code pow($n, 14)} → {@code (((($n*$n*$n)*($n*$n*$n))*$n)*((($n*$n*$n)*($n*$n*$n))*$n))}
     *   with chained smul overflow→float (seventh × seventh)
     * - {@code $n ** 15} / {@code pow($n, 15)} → {@code ((((($n*$n*$n)*($n*$n*$n))*$n)*((($n*$n*$n)*($n*$n*$n))*$n))*$n)}
     *   with chained smul overflow→float (fourteenth × n)
     * - {@code $n ** 16} / {@code pow($n, 16)} → {@code (((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))}
     *   with chained smul overflow→float (eighth × eighth)
     * - {@code $n ** 17} / {@code pow($n, 17)} → {@code ((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n}
     *   with chained smul overflow→float (sixteenth × n)
     * - {@code $n ** 18} / {@code pow($n, 18)} → {@code (((($n*$n*$n)*($n*$n*$n))*($n*$n*$n))*((($n*$n*$n)*($n*$n*$n))*($n*$n*$n)))}
     *   with chained smul overflow→float (ninth × ninth)
     * - {@code $n ** 19} / {@code pow($n, 19)} → {@code ((((($n*$n*$n)*($n*$n*$n))*($n*$n*$n))*((($n*$n*$n)*($n*$n*$n))*($n*$n*$n)))*$n}
     *   with chained smul overflow→float (eighteenth × n)
     * - {@code $n ** 20} / {@code pow($n, 20)} → {@code (((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n))))}
     *   with chained smul overflow→float (tenth × tenth)
     * - {@code $n ** 21} / {@code pow($n, 21)} → {@code ((((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n))))*$n}
     *   with chained smul overflow→float (twentieth × n)
     * - {@code $n ** 22} / {@code pow($n, 22)} → {@code ((((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n)*(((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n))}
     *   with chained smul overflow→float (eleventh × eleventh)
     * - {@code $n ** 23} / {@code pow($n, 23)} → {@code (((((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n)*(((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n))*$n}
     *   with chained smul overflow→float (twentysecond × n)
     * - {@code $n ** 24} / {@code pow($n, 24)} → {@code (((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n))))}
     *   with chained smul overflow→float (twelfth × twelfth)
     * - {@code $n ** 25} / {@code pow($n, 25)} → {@code ((((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n))))*$n}
     *   with chained smul overflow→float (twentyfourth × n)
     * - {@code $n ** 26} / {@code pow($n, 26)} → {@code (((((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n)*(((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n))}
     *   with chained smul overflow→float (thirteenth × thirteenth)
     * - {@code $n ** 27} / {@code pow($n, 27)} → {@code ((((((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n)*(((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n))*$n}
     *   with chained smul overflow→float (twentysixth × n)
     * - {@code $n ** 28} / {@code pow($n, 28)} → {@code (((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*(((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n)))}
     *   with chained smul overflow→float (fourteenth × fourteenth)
     * - {@code $n ** 29} / {@code pow($n, 29)} → {@code ((((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*(((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n)))*$n}
     *   with chained smul overflow→float (twentyeighth × n)
     * - {@code $n ** 30} / {@code pow($n, 30)} → {@code (((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*$n)*((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*$n)}
     *   with chained smul overflow→float (fifteenth × fifteenth)
     * - {@code $n ** 31} / {@code pow($n, 31)} → {@code ((((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*$n)*((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*$n))*$n}
     *   with chained smul overflow→float (thirtieth × n)
     * - {@code $n ** 32} / {@code pow($n, 32)} → {@code (((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))))}
     *   with chained smul overflow→float (sixteenth × sixteenth)
     * - {@code $n ** 33} / {@code pow($n, 33)} → {@code ((((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))))*$n}
     *   with chained smul overflow→float (thirtysecond × n)
     * - {@code $n ** 34} / {@code pow($n, 34)} → {@code ((((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n)*(((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n))}
     *   with chained smul overflow→float (seventeenth × seventeenth)
     * - {@code $n ** 35} / {@code pow($n, 35)} → {@code (((((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n)*(((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n))*$n}
     *   with chained smul overflow→float (thirtyfourth × n)
     * - {@code $n ** 36} / {@code pow($n, 36)} → {@code ((((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))))*((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n)))))}
     *   with chained smul overflow→float (eighteenth × eighteenth)
     * - {@code $n ** 37} / {@code pow($n, 37)} → {@code (((((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))))*((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n)))))*$n}
     *   with chained smul overflow→float (thirtysixth × n)
     * - {@code $n ** 38} / {@code pow($n, 38)} → {@code ((((((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))))*((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n)))))*$n)*$n}
     *   with chained smul overflow→float (thirtyseventh × n)
     * - {@code $n ** 39} / {@code pow($n, 39)} → {@code (((((((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))))*((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n)))))*$n)*$n)*$n}
     *   with chained smul overflow→float (thirtyeighth × n)
     * - {@code $n ** 40} / {@code pow($n, 40)} → {@code (((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))}
     *   with chained smul overflow→float (twentieth × twentieth)
     * - {@code $n ** 41} / {@code pow($n, 41)} → {@code ((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*$n}
     *   with chained smul overflow→float (fortieth × n)
     * - {@code $n ** 42} / {@code pow($n, 42)} → {@code ((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n)}
     *   with chained smul overflow→float (fortieth × square)
     * - {@code $n ** 43} / {@code pow($n, 43)} → {@code (((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*$n}
     *   with chained smul overflow→float (fortysecond × n)
     * - {@code $n ** 44} / {@code pow($n, 44)} → {@code (((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fortysecond × square)
     * - {@code $n ** 45} / {@code pow($n, 45)} → {@code ((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fortyfourth × n)
     * - {@code $n ** 46} / {@code pow($n, 46)} → {@code ((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fortyfourth × square)
     * - {@code $n ** 47} / {@code pow($n, 47)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fortysixth × n)
     * - {@code $n ** 48} / {@code pow($n, 48)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fortysixth × square)
     * - {@code $n ** 49} / {@code pow($n, 49)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fortyeighth × n)
     * - {@code $n ** 50} / {@code pow($n, 50)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fortyeighth × square)
     * - {@code $n ** 51} / {@code pow($n, 51)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fiftieth × n)
     * - {@code $n ** 52} / {@code pow($n, 52)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fiftyfirst × n)
     * - {@code $n ** 53} / {@code pow($n, 53)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fiftysecond × n)
     * - {@code $n ** 54} / {@code pow($n, 54)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n}
     *   with chained smul overflow→float (fiftythird × n)
     * - {@code $n ** 55} / {@code pow($n, 55)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyfourth × n)
     * - {@code $n ** 56} / {@code pow($n, 56)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyfifth × n)
     * - {@code $n ** 57} / {@code pow($n, 57)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftysixth × n)
     * - {@code $n ** 58} / {@code pow($n, 58)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyseventh × n)
     * - {@code $n ** 59} / {@code pow($n, 59)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyeighth × n)
     * - {@code $n ** 60} / {@code pow($n, 60)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyninth × n)
     * - {@code $n ** 61} / {@code pow($n, 61)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtieth × n)
     * - {@code $n ** 62} / {@code pow($n, 62)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtyfirst × n)
     * - {@code $n ** 63} / {@code pow($n, 63)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtysecond × n)
     * - {@code $n ** 64} / {@code pow($n, 64)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtythird × n)
     * - {@code $n ** 65} / {@code pow($n, 65)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtyfourth × n)
     * - {@code $n ** 66} / {@code pow($n, 66)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n*$n}
     *   with chained smul overflow→float (sixtyfifth × n)
     * - {@code $n ** 67} / {@code pow($n, 67)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n*$n)*$n}
     *   with chained smul overflow→float (sixtysixth × n)
     * - {@code $n ** 68} / {@code pow($n, 68)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtyseventh × n)
     * - {@code $n ** 69} / {@code pow($n, 69)} → chained smul overflow→float (sixtyeighth × n)
     * - {@code $n ** 70} / {@code pow($n, 70)} → chained smul overflow→float (sixtyninth × n)
     * - {@code $n ** 71} / {@code pow($n, 71)} → chained smul overflow→float (seventieth × n)
     * - {@code $n ** 72} / {@code pow($n, 72)} → chained smul overflow→float (seventyfirst × n)
     * - {@code $n ** 73} / {@code pow($n, 73)} → chained smul overflow→float (seventysecond × n)
     * - {@code $n ** 74} / {@code pow($n, 74)} → chained smul overflow→float (seventythird × n)
     * - {@code $n ** 75} / {@code pow($n, 75)} → chained smul overflow→float (seventyfourth × n)
     *
     * Omits {@code llvm.pow.f64} and the siToFp/fpToSi round-trip on the
     * integer fast path ({@see \PHPCompiler\ext\standard\JitPow}). Peer
     * compile-time {@code * 1} identity ({@see nativeLongArithIsCompileTimeIdentityOrZero})
     * and {@code * 2^k} shl ({@see nativeLongMulCompileTimePowerOfTwoShift}).
     *
     * Float exponents ({@code 0.0}/{@code 1.0}/{@code 2.0}/{@code 3.0}/{@code 4.0}/{@code 5.0}/{@code 6.0}/{@code 7.0}/{@code 8.0}/{@code 9.0}/{@code 10.0}/{@code 11.0}/{@code 12.0}/{@code 13.0}/{@code 14.0}/{@code 15.0}/{@code 16.0}/{@code 17.0}/{@code 18.0}/{@code 19.0}/{@code 20.0}/{@code 21.0}/{@code 22.0}/{@code 23.0}/{@code 24.0}/{@code 25.0}/{@code 26.0}/{@code 27.0}/{@code 28.0}/{@code 29.0}/{@code 30.0}/{@code 31.0}/{@code 32.0}/{@code 33.0}/{@code 34.0}/{@code 35.0}/{@code 36.0}/{@code 37.0}/{@code 38.0}/{@code 39.0}/{@code 40.0}/{@code 41.0}/{@code 42.0}/{@code 43.0}/{@code 44.0}/{@code 45.0}/{@code 46.0}/{@code 47.0}/{@code 48.0}/{@code 49.0}/{@code 50.0}/{@code 51.0}/{@code 52.0}/{@code 53.0}/{@code 54.0}/{@code 55.0}/{@code 56.0}/{@code 57.0}/{@code 58.0}/{@code 59.0}/{@code 60.0}/{@code 61.0}/{@code 62.0}/{@code 63.0}/{@code 64.0}/{@code 65.0}/{@code 66.0}/{@code 67.0}/{@code 68.0}/{@code 69.0}/{@code 70.0}/{@code 71.0}/{@code 72.0}/{@code 73.0}/{@code 74.0}/{@code 75.0}) stay
     * on the float path — Zend returns {@code float} for those shapes.
     *
     * php-src: Zend/zend_operators.c {@code pow_function} /
     * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
     * {@code PHP_FUNCTION(pow)}.
     *
     * @return 'one'|'identity'|'square'|'cube'|'fourth'|'fifth'|'sixth'|'seventh'|'eighth'|'ninth'|'tenth'|'eleventh'|'twelfth'|'thirteenth'|'fourteenth'|'fifteenth'|'sixteenth'|'seventeenth'|'eighteenth'|'nineteenth'|'twentieth'|'twentyfirst'|'twentysecond'|'twentythird'|'twentyfourth'|'twentyfifth'|'twentysixth'|'twentyseventh'|'twentyeighth'|'twentyninth'|'thirtieth'|'thirtyfirst'|'thirtysecond'|'thirtythird'|'thirtyfourth'|'thirtyfifth'|'thirtysixth'|'thirtyseventh'|'thirtyeighth'|'thirtyninth'|'fortieth'|'fortyfirst'|'fortysecond'|'fortythird'|'fortyfourth'|'fortyfifth'|'fortysixth'|'fortyseventh'|'fortyeighth'|'fortyninth'|'fiftieth'|'fiftyfirst'|'fiftysecond'|'fiftythird'|'fiftyfourth'|'fiftyfifth'|'fiftysixth'|'fiftyseventh'|'fiftyeighth'|'fiftyninth'|'sixtieth'|'sixtyfirst'|'sixtysecond'|'sixtythird'|'sixtyfourth'|'sixtyfifth'|'sixtysixth'|'sixtyseventh'|'sixtyeighth'|'sixtyninth'|'seventieth'|'seventyfirst'|'seventysecond'|'seventythird'|'seventyfourth'|'seventyfifth'|null fold to 1, keep base, mul square/cube/fourth/fifth/sixth/seventh/eighth/ninth/tenth/eleventh/twelfth/thirteenth/fourteenth/fifteenth/sixteenth/seventeenth/eighteenth/nineteenth/twentieth/twentyfirst/twentysecond/twentythird/twentyfourth/twentyfifth/twentysixth/twentyseventh/twentyeighth/twentyninth/thirtieth/thirtyfirst/thirtysecond/thirtythird/thirtyfourth/thirtyfifth/thirtysixth/thirtyseventh/thirtyeighth/thirtyninth/fortieth/fortyfirst/fortysecond/fortythird/fortyfourth/fortyfifth/fortysixth/fortyseventh/fortyeighth/fortyninth/fiftieth/fiftyfirst/fiftysecond/fiftythird/fiftyfourth/fiftyfifth/fiftysixth/fiftyseventh/fiftyeighth/fiftyninth/sixtieth/sixtyfirst/sixtysecond/sixtythird/sixtyfourth, sixtyfifth, sixtysixth, sixtyseventh, sixtyeighth, sixtyninth, seventieth, seventyfirst, seventysecond, seventythird, seventyfourth, seventyfifth, or null
     */
    public static function nativeLongPowCompileTimeExponentFold(
        Variable $exponent
    ): ?string {
        $e = self::compileTimeLongScalar($exponent);
        if (null === $e) {
            return null;
        }
        if (0 === $e) {
            return 'one';
        }
        if (1 === $e) {
            return 'identity';
        }
        if (2 === $e) {
            return 'square';
        }
        if (3 === $e) {
            return 'cube';
        }
        if (4 === $e) {
            return 'fourth';
        }
        if (5 === $e) {
            return 'fifth';
        }
        if (6 === $e) {
            return 'sixth';
        }
        if (7 === $e) {
            return 'seventh';
        }
        if (8 === $e) {
            return 'eighth';
        }
        if (9 === $e) {
            return 'ninth';
        }
        if (10 === $e) {
            return 'tenth';
        }
        if (11 === $e) {
            return 'eleventh';
        }
        if (12 === $e) {
            return 'twelfth';
        }
        if (13 === $e) {
            return 'thirteenth';
        }
        if (14 === $e) {
            return 'fourteenth';
        }
        if (15 === $e) {
            return 'fifteenth';
        }
        if (16 === $e) {
            return 'sixteenth';
        }
        if (17 === $e) {
            return 'seventeenth';
        }
        if (18 === $e) {
            return 'eighteenth';
        }
        if (19 === $e) {
            return 'nineteenth';
        }
        if (20 === $e) {
            return 'twentieth';
        }
        if (21 === $e) {
            return 'twentyfirst';
        }
        if (22 === $e) {
            return 'twentysecond';
        }
        if (23 === $e) {
            return 'twentythird';
        }
        if (24 === $e) {
            return 'twentyfourth';
        }
        if (25 === $e) {
            return 'twentyfifth';
        }
        if (26 === $e) {
            return 'twentysixth';
        }
        if (27 === $e) {
            return 'twentyseventh';
        }
        if (28 === $e) {
            return 'twentyeighth';
        }
        if (29 === $e) {
            return 'twentyninth';
        }
        if (30 === $e) {
            return 'thirtieth';
        }
        if (31 === $e) {
            return 'thirtyfirst';
        }
        if (32 === $e) {
            return 'thirtysecond';
        }
        if (33 === $e) {
            return 'thirtythird';
        }
        if (34 === $e) {
            return 'thirtyfourth';
        }
        if (35 === $e) {
            return 'thirtyfifth';
        }
        if (36 === $e) {
            return 'thirtysixth';
        }
        if (37 === $e) {
            return 'thirtyseventh';
        }
        if (38 === $e) {
            return 'thirtyeighth';
        }
        if (39 === $e) {
            return 'thirtyninth';
        }
        if (40 === $e) {
            return 'fortieth';
        }
        if (41 === $e) {
            return 'fortyfirst';
        }
        if (42 === $e) {
            return 'fortysecond';
        }
        if (43 === $e) {
            return 'fortythird';
        }
        if (44 === $e) {
            return 'fortyfourth';
        }
        if (45 === $e) {
            return 'fortyfifth';
        }
        if (46 === $e) {
            return 'fortysixth';
        }
        if (47 === $e) {
            return 'fortyseventh';
        }
        if (48 === $e) {
            return 'fortyeighth';
        }
        if (49 === $e) {
            return 'fortyninth';
        }
        if (50 === $e) {
            return 'fiftieth';
        }
        if (51 === $e) {
            return 'fiftyfirst';
        }
        if (52 === $e) {
            return 'fiftysecond';
        }
        if (53 === $e) {
            return 'fiftythird';
        }
        if (54 === $e) {
            return 'fiftyfourth';
        }
        if (55 === $e) {
            return 'fiftyfifth';
        }
        if (56 === $e) {
            return 'fiftysixth';
        }
        if (57 === $e) {
            return 'fiftyseventh';
        }
        if (58 === $e) {
            return 'fiftyeighth';
        }
        if (59 === $e) {
            return 'fiftyninth';
        }
        if (60 === $e) {
            return 'sixtieth';
        }
        if (61 === $e) {
            return 'sixtyfirst';
        }
        if (62 === $e) {
            return 'sixtysecond';
        }
        if (63 === $e) {
            return 'sixtythird';
        }
        if (64 === $e) {
            return 'sixtyfourth';
        }
        if (65 === $e) {
            return 'sixtyfifth';
        }
        if (66 === $e) {
            return 'sixtysixth';
        }
        if (67 === $e) {
            return 'sixtyseventh';
        }
        if (68 === $e) {
            return 'sixtyeighth';
        }
        if (69 === $e) {
            return 'sixtyninth';
        }
        if (70 === $e) {
            return 'seventieth';
        }
        if (71 === $e) {
            return 'seventyfirst';
        }
        if (72 === $e) {
            return 'seventysecond';
        }
        if (73 === $e) {
            return 'seventythird';
        }
        if (74 === $e) {
            return 'seventyfourth';
        }
        if (75 === $e) {
            return 'seventyfifth';
        }

        return null;
    }

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
