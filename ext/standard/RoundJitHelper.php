<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * round() for compiled JIT/AOT modules + NestedJIT-safe algorithm SSOT (#15211, #26800).
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
        $exponent = 1.0;
        for ($i = 0; $i < $ap; ++$i) {
            $exponent *= 10.0;
        }

        if ($num >= 0.0) {
            $scaled = $places > 0 ? $num * $exponent : $num / $exponent;
            $tmpValue = (float) (int) $scaled;
            $tmpValue2 = $tmpValue + 1.0;
        } else {
            $scaled = $places > 0 ? $num * $exponent : $num / $exponent;
            $asInt = (int) $scaled;
            $asFloat = (float) $asInt;
            if ($asFloat === $scaled) {
                $tmpValue = $asFloat;
            } else {
                $tmpValue = (float) ($asInt - 1);
            }
            $tmpValue2 = $tmpValue - 1.0;
        }

        $recon = $places > 0 ? $tmpValue2 / $exponent : $tmpValue2 * $exponent;
        if ($recon === $num) {
            $tmpValue = $tmpValue2;
        }

        $mag = $tmpValue < 0.0 ? -$tmpValue : $tmpValue;
        if ($mag >= 1.0e16) {
            return $num;
        }

        $tmpValue = self::applyMode($tmpValue, $num, $exponent, $places, $mode);

        if ($places > 0) {
            return $tmpValue / $exponent;
        }

        return $tmpValue * $exponent;
    }

    private static function applyMode(
        float $integral,
        float $value,
        float $exponent,
        int $places,
        int $mode
    ): float {
        $valueAbs = $value < 0.0 ? -$value : $value;
        $half = $value >= 0.0 ? 0.5 : -0.5;
        $edge = $places > 0
            ? (($integral + $half) / $exponent)
            : (($integral + $half) * $exponent);
        if ($edge < 0.0) {
            $edge = -$edge;
        }
        $zeroEdge = $places > 0 ? ($integral / $exponent) : ($integral * $exponent);
        if ($zeroEdge < 0.0) {
            $zeroEdge = -$zeroEdge;
        }
        $step = $value >= 0.0 ? 1.0 : -1.0;

        // Literals — PHP_ROUND_* (ext/standard/php_math_round_mode.h)
        if (2 === $mode) { // HALF_DOWN
            return $valueAbs > $edge ? $integral + $step : $integral;
        }
        if (3 === $mode) { // HALF_EVEN
            if ($valueAbs > $edge) {
                return $integral + $step;
            }
            if ($valueAbs === $edge) {
                $parity = (int) $integral;
                if (0 !== ($parity % 2)) {
                    return $integral + $step;
                }
            }

            return $integral;
        }
        if (4 === $mode) { // HALF_ODD
            if ($valueAbs > $edge) {
                return $integral + $step;
            }
            if ($valueAbs === $edge) {
                $parity = (int) $integral;
                if (0 === ($parity % 2)) {
                    return $integral + $step;
                }
            }

            return $integral;
        }
        if (5 === $mode) { // CEILING
            return ($value > 0.0 && $valueAbs > $zeroEdge) ? $integral + 1.0 : $integral;
        }
        if (6 === $mode) { // FLOOR
            return ($value < 0.0 && $valueAbs > $zeroEdge) ? $integral - 1.0 : $integral;
        }
        if (7 === $mode) { // TOWARD_ZERO
            return $integral;
        }
        if (8 === $mode) { // AWAY_FROM_ZERO
            return $valueAbs > $zeroEdge ? $integral + $step : $integral;
        }

        // HALF_UP (1) and unknown modes — php-src default
        return ($valueAbs > $edge || $valueAbs === $edge) ? $integral + $step : $integral;
    }

    /**
     * places==0 NestedJIT fast path — avoid exponent scale (returns 0 under NestedJIT).
     */
    private static function roundPlacesZero(float $num, int $mode): float
    {
        $valueAbs = $num < 0.0 ? -$num : $num;
        $asInt = (int) $num;
        $asFloat = (float) $asInt;
        if ($num >= 0.0) {
            $integral = $asFloat;
        } elseif ($asFloat === $num) {
            $integral = $asFloat;
        } else {
            $integral = (float) ($asInt - 1);
        }
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
