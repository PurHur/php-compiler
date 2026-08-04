<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ceil() for compiled JIT/AOT modules (#15129, #27003, #27650, php-in-PHP).
 *
 * NestedJIT-safe trunc algorithm (peer {@see FloorJitHelper} / RoundJitHelper).
 * Avoid `\ceil` / {@see VmMath::ceil} — NestedJIT re-enters MathCeil bridge under thin AOT.
 * Avoid large float literals in compares — NestedJIT mis-folds `$num <= -2^53` for ordinary
 * negatives (#27650). Values beyond int64 range share RoundJitHelper's NestedJIT saturation.
 * php-src: ext/standard/math.c — PHP_FUNCTION(ceil)
 */
final class CeilJitHelper
{
    public static function ceilArgv(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        // Reject ±Inf without `\is_finite` / isinf helpers (RoundJitHelper peer).
        $probe = 1.0e+308;
        $probe = $probe * $probe;
        if ($num === $probe || $num === -$probe) {
            return $num;
        }
        if (0.0 === $num) {
            return $num;
        }

        $asInt = (int) $num;
        $asFloat = (float) $asInt;
        if ($num <= 0.0) {
            // Trunc toward zero of (-1,0) yields +0; Zend ceil returns -0.0.
            if (0.0 === $asFloat && $num < 0.0) {
                return -0.0;
            }

            return $asFloat;
        }
        if ($asFloat === $num) {
            return $asFloat;
        }

        return (float) ($asInt + 1);
    }
}
