<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * floor() for compiled JIT/AOT modules (#15128, #27004, #27650, php-in-PHP).
 *
 * NestedJIT-safe trunc algorithm (peer {@see RoundJitHelper::roundPlacesZero} / Abs).
 * Avoid `\floor` / {@see VmMath::floor} — NestedJIT re-enters MathFloor bridge under thin AOT.
 * Avoid large float literals in compares — NestedJIT mis-folds `$num <= -2^53` for ordinary
 * negatives (#27650). Values beyond int64 range share RoundJitHelper's NestedJIT saturation.
 * php-src: ext/standard/math.c — PHP_FUNCTION(floor)
 */
final class FloorJitHelper
{
    public static function floorArgv(float $num): float
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
        if ($num >= 0.0) {
            return $asFloat;
        }
        if ($asFloat === $num) {
            return $asFloat;
        }

        return (float) ($asInt - 1);
    }
}
