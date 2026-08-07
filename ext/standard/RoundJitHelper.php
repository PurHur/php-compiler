<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * round() for compiled JIT/AOT modules + NestedJIT-safe algorithm SSOT (#15211, #26800, #27248).
 *
 * Same-class only (peer AbsJitHelper). Avoid `\is_finite`/`\floor`/`\ceil`/`\abs`/`\fmod`
 * — NestedJIT re-enters *JitHelper bridges (gdb: isfiniteargv ↔ phpc_is_finite).
 * Avoid private const in switch arms — use literal int if/else (#26794 class-const peer).
 * php-src: ext/standard/math.c — _php_math_round
 */
final class RoundJitHelper
{
    public static function roundArgv(float $num, int $places, int $mode): float
    {
        if ($num !== $num) {
            return $num;
        }
        // Reject ±Inf without `\is_finite` / isinf helpers.
        $probe = 1.0e+308;
        $probe = $probe * $probe;
        if ($num === $probe || $num === -$probe) {
            return $num;
        }
        if (0.0 === $num) {
            return $num;
        }

        // NestedJIT places==0 path via exponent is unreliable (#26800) — fast path.
        if (0 === $places) {
            return self::roundPlacesZero($num, $mode);
        }

        if ($places < -9223372036854775807) {
            $places = -9223372036854775807;
        }
        if ($places > 308) {
            $places = 308;
        }
        if ($places < -308) {
            $places = -308;
        }

        $ap = $places < 0 ? -$places : $places;
        // NestedJIT-safe scale: pow10 table + places==0 kernel (#27248 / #27249).
        // Avoid the old fmul/cast/applyMode body — cold thin AOT mis-rounded (3 / 3.1).
        $exponent = self::pow10abs($ap);
        if ($places > 0) {
            $scaled = $num * $exponent;
        } else {
            $scaled = $num / $exponent;
        }
        $mag = $scaled < 0.0 ? -$scaled : $scaled;
        if ($mag >= 1.0e16) {
            return $num;
        }
        $rounded = self::roundPlacesZero($scaled, $mode);
        if ($places > 0) {
            return $rounded / $exponent;
        }

        return $rounded * $exponent;
    }

    /**
     * 10**n for NestedJIT — no for-loop; no int `===` (cold AOT mis-matches, #27248).
     *
     * Use `$n < k` ladders only — NestedJIT int equality on the places abs value is unreliable.
     */
    private static function pow10abs(int $n): float
    {
        if ($n < 1) {
            return 1.0;
        }
        if ($n < 2) {
            return 10.0;
        }
        if ($n < 3) {
            return 100.0;
        }
        if ($n < 4) {
            return 1000.0;
        }
        if ($n < 5) {
            return 10000.0;
        }
        if ($n < 6) {
            return 100000.0;
        }
        if ($n < 7) {
            return 1000000.0;
        }
        if ($n < 8) {
            return 10000000.0;
        }
        if ($n < 9) {
            return 100000000.0;
        }
        if ($n < 10) {
            return 1000000000.0;
        }
        if ($n < 11) {
            return 10000000000.0;
        }
        if ($n < 12) {
            return 100000000000.0;
        }
        if ($n < 13) {
            return 1000000000000.0;
        }
        if ($n < 14) {
            return 10000000000000.0;
        }
        if ($n < 15) {
            return 100000000000000.0;
        }
        if ($n < 16) {
            return 1.0e15;
        }
        if ($n < 17) {
            return 1.0e16;
        }
        if ($n < 18) {
            return 1.0e17;
        }
        if ($n < 19) {
            return 1.0e18;
        }
        if ($n < 20) {
            return 1.0e19;
        }
        if ($n < 21) {
            return 1.0e20;
        }
        if ($n < 22) {
            return 1.0e21;
        }
        if ($n < 23) {
            return 1.0e22;
        }
        $exponent = 1.0e22;
        $left = $n - 22;
        while ($left > 0) {
            $exponent *= 10.0;
            $left = $left - 1;
        }

        return $exponent;
    }

    /**
     * places==0 NestedJIT fast path — avoid exponent scale (returns 0 under NestedJIT).
     *
     * Integral part matches php-src _php_math_round: floor for positives, ceil for
     * negatives (toward-zero truncation) — #28534.
     */
    private static function roundPlacesZero(float $num, int $mode): float
    {
        $valueAbs = $num < 0.0 ? -$num : $num;
        // PHP (int) cast truncates toward zero ≡ floor(x≥0) / ceil(x<0).
        $asInt = (int) $num;
        $integral = (float) $asInt;
        $step = $num >= 0.0 ? 1.0 : -1.0;
        $half = $num >= 0.0 ? 0.5 : -0.5;
        $edge = $integral + $half;
        if ($edge < 0.0) {
            $edge = -$edge;
        }
        $zeroEdge = $integral < 0.0 ? -$integral : $integral;

        if (2 === $mode) {
            return $valueAbs > $edge ? $integral + $step : $integral;
        }
        if (3 === $mode) {
            if ($valueAbs > $edge) {
                return $integral + $step;
            }
            if ($valueAbs === $edge && 0 !== ($asInt % 2)) {
                return $integral + $step;
            }

            return $integral;
        }
        if (4 === $mode) {
            if ($valueAbs > $edge) {
                return $integral + $step;
            }
            if ($valueAbs === $edge && 0 === ($asInt % 2)) {
                return $integral + $step;
            }

            return $integral;
        }
        if (5 === $mode) {
            return ($num > 0.0 && $valueAbs > $zeroEdge) ? $integral + 1.0 : $integral;
        }
        if (6 === $mode) {
            return ($num < 0.0 && $valueAbs > $zeroEdge) ? $integral - 1.0 : $integral;
        }
        if (7 === $mode) {
            return $integral;
        }
        if (8 === $mode) {
            return $valueAbs > $zeroEdge ? $integral + $step : $integral;
        }

        if ($valueAbs > $edge || $valueAbs === $edge) {
            return $integral + $step;
        }

        return $integral;
    }
}
