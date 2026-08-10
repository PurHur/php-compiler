<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * int**int fast path for compiled JIT/AOT modules (#9515, #29678, php-in-PHP).
 *
 * NestedJIT-safe: bounded successive squaring + signed-mul overflow peel (#29678 /
 * peer Fpow #28674 / Ldexp #29578). Do not call the shared VmMath int-pow helper /
 * the `**` operator / `\pow` / `\is_int` — NestedJIT re-enters pow / type bridges
 * under thin AOT (#27496 class). Avoid bit ops (parity via intdiv). Avoid
 * unbounded while-loops (#27838). Avoid compound `&&` / `||` conditions (#28716).
 * Tag int vs float via control-flow path (LLVM i32 ABI), not runtime type checks.
 *
 * VM SSOT remains VmMath int-pow for the interpreter.
 * php-src: Zend/zend_operators.c — pow_function integer fast path
 *          (ZEND_SIGNED_MULTIPLY_LONG successive squaring)
 */
final class PowIntJitHelper
{
    private static int $lastInt = 0;

    private static float $lastFloat = 0.0;

    /** @return int 0=int result, 1=float result (LLVM i32 ABI) */
    public static function compute(int $base, int $exp): int
    {
        if ($exp < 0) {
            self::$lastFloat = self::floatPow($base, $exp);

            return 1;
        }
        if (0 === $exp) {
            self::$lastInt = 1;

            return 0;
        }

        $result = 1;
        $b = $base;
        $e = $exp;
        for ($i = 0; $i < 64; ++$i) {
            if ($e <= 0) {
                break;
            }
            $half = \intdiv($e, 2);
            if ($e !== $half + $half) {
                if (self::mulOverflows($result, $b)) {
                    self::$lastFloat = self::floatPow($base, $exp);

                    return 1;
                }
                $result = $result * $b;
            }
            if ($half > 0) {
                if (self::mulOverflows($b, $b)) {
                    self::$lastFloat = self::floatPow($base, $exp);

                    return 1;
                }
                $b = $b * $b;
            }
            $e = $half;
        }
        self::$lastInt = $result;

        return 0;
    }

    public static function resultInt(): int
    {
        return self::$lastInt;
    }

    public static function resultFloat(): float
    {
        return self::$lastFloat;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$lastInt = 0;
        self::$lastFloat = 0.0;
    }

    /**
     * Signed multiply overflow — Zend ZEND_SIGNED_MULTIPLY_LONG shape.
     * Uses intdiv (not float `/`) so limits stay exact beyond 2^53.
     */
    private static function mulOverflows(int $a, int $b): bool
    {
        if (0 === $a) {
            return false;
        }
        if (0 === $b) {
            return false;
        }
        $max = \PHP_INT_MAX;
        $min = \PHP_INT_MIN;
        if ($a > 0) {
            if ($b > 0) {
                if ($a > \intdiv($max, $b)) {
                    return true;
                }

                return false;
            }
            if ($b < \intdiv($min, $a)) {
                return true;
            }

            return false;
        }
        if ($b > 0) {
            if ($a < \intdiv($min, $b)) {
                return true;
            }

            return false;
        }
        // both negative — a * b overflows when a < max/b (or a == MIN && b == -1)
        if (-1 === $b) {
            if ($a === $min) {
                return true;
            }

            return false;
        }
        if ($a < \intdiv($max, $b)) {
            return true;
        }

        return false;
    }

    /**
     * Float-domain successive squaring for neg exp / int overflow (#28674 powByInt shape).
     */
    private static function floatPow(int $base, int $exp): float
    {
        if (0 === $exp) {
            return 1.0;
        }
        if (0 === $base) {
            if ($exp > 0) {
                return 0.0;
            }
            $inf = 1.0e+308;
            $inf = $inf * $inf;

            return $inf;
        }

        $neg = $exp < 0;
        if ($neg) {
            if ($exp === \PHP_INT_MIN) {
                // |exp| = 2^63 — successive-square the float base 63 times, then invert.
                $b = (float) $base;
                for ($i = 0; $i < 63; ++$i) {
                    $b *= $b;
                }

                return 1.0 / $b;
            }
        }

        $e = $neg ? -$exp : $exp;
        $result = 1.0;
        $b = (float) $base;
        for ($i = 0; $i < 64; ++$i) {
            if ($e <= 0) {
                break;
            }
            $half = \intdiv($e, 2);
            if ($e !== $half + $half) {
                $result *= $b;
            }
            $b *= $b;
            $e = $half;
        }
        if ($neg) {
            return 1.0 / $result;
        }

        return $result;
    }
}
