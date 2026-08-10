<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * clamp() for compiled JIT/AOT modules (#17336, #29730, php-in-PHP).
 *
 * NestedJIT-safe peel of the shared VmMath clamp helper (#29730 / peer Ldexp
 * #29578): NAN via `$x !== $x` (no `\is_nan`). Ordering uses same-class
 * numeric compare (no Variable spaceship helpers — #26884). Avoid compound
 * `&&` / `||` (#28716).
 * php-src: ext/standard/math.c — PHP_FUNCTION(clamp), php_math_clamp
 */
final class ClampJitHelper
{
    public static function clampArgv(Variable $value, Variable $min, Variable $max): Variable
    {
        $out = new Variable();
        $value = $value->resolveIndirect();
        $min = $min->resolveIndirect();
        $max = $max->resolveIndirect();

        if (Variable::TYPE_FLOAT === $min->type) {
            $minF = $min->toFloat();
            if ($minF !== $minF) {
                throw new \ValueError('clamp(): Argument #2 ($min) must not be NAN');
            }
        }
        if (Variable::TYPE_FLOAT === $max->type) {
            $maxF = $max->toFloat();
            if ($maxF !== $maxF) {
                throw new \ValueError('clamp(): Argument #3 ($max) must not be NAN');
            }
        }

        $maxMin = self::cmp($max, $min);
        if ($maxMin < 0) {
            throw new \ValueError(
                'clamp(): Argument #2 ($min) must be smaller than or equal to argument #3 ($max)'
            );
        }

        $maxValue = self::cmp($max, $value);
        if ($maxValue < 0) {
            $out->copyFrom($max);

            return $out;
        }

        $valueMin = self::cmp($value, $min);
        if ($valueMin < 0) {
            $out->copyFrom($min);

            return $out;
        }

        $out->copyFrom($value);

        return $out;
    }

    /** NestedJIT-safe int/float ordering — same-class only. */
    private static function cmp(Variable $left, Variable $right): int
    {
        $lt = $left->type;
        $rt = $right->type;

        if (Variable::TYPE_INTEGER === $lt) {
            if (Variable::TYPE_INTEGER === $rt) {
                $a = $left->toInt();
                $b = $right->toInt();
                if ($a < $b) {
                    return -1;
                }
                if ($a > $b) {
                    return 1;
                }

                return 0;
            }
        }

        if (Variable::TYPE_INTEGER === $lt) {
            if (Variable::TYPE_FLOAT === $rt) {
                $a = $left->toInt();
                $af = $a + 0.0;
                $bf = $right->toFloat();
                if ($af < $bf) {
                    return -1;
                }
                if ($af > $bf) {
                    return 1;
                }

                return 0;
            }
        }

        if (Variable::TYPE_FLOAT === $lt) {
            if (Variable::TYPE_FLOAT === $rt) {
                $af = $left->toFloat();
                $bf = $right->toFloat();
                if ($af < $bf) {
                    return -1;
                }
                if ($af > $bf) {
                    return 1;
                }

                return 0;
            }
        }

        if (Variable::TYPE_FLOAT === $lt) {
            if (Variable::TYPE_INTEGER === $rt) {
                $af = $left->toFloat();
                $b = $right->toInt();
                $bf = $b + 0.0;
                if ($af < $bf) {
                    return -1;
                }
                if ($af > $bf) {
                    return 1;
                }

                return 0;
            }
        }

        $af = $left->toFloat();
        $bf = $right->toFloat();
        if ($af < $bf) {
            return -1;
        }
        if ($af > $bf) {
            return 1;
        }

        return 0;
    }
}
