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

        $apf = (float) $places;
        if ($apf < 0.0) {
            $apf = -$apf;
        }
        // Inline pow10 ladder — private static pow10abs() call aborts under thin AOT (#35741).
        $exponent = 1.0;
        if ($apf >= 1.0) {
            if ($apf < 2.0) {
                $exponent = 10.0;
            } elseif ($apf < 3.0) {
                $exponent = 100.0;
            } elseif ($apf < 4.0) {
                $exponent = 1000.0;
            } elseif ($apf < 5.0) {
                $exponent = 10000.0;
            } elseif ($apf < 6.0) {
                $exponent = 100000.0;
            } elseif ($apf < 7.0) {
                $exponent = 1000000.0;
            } elseif ($apf < 8.0) {
                $exponent = 10000000.0;
            } elseif ($apf < 9.0) {
                $exponent = 100000000.0;
            } elseif ($apf < 10.0) {
                $exponent = 1000000000.0;
            } elseif ($apf < 11.0) {
                $exponent = 10000000000.0;
            } elseif ($apf < 12.0) {
                $exponent = 100000000000.0;
            } elseif ($apf < 13.0) {
                $exponent = 1000000000000.0;
            } elseif ($apf < 14.0) {
                $exponent = 10000000000000.0;
            } elseif ($apf < 15.0) {
                $exponent = 100000000000000.0;
            } elseif ($apf < 16.0) {
                $exponent = 1.0e15;
            } elseif ($apf < 17.0) {
                $exponent = 1.0e16;
            } elseif ($apf < 18.0) {
                $exponent = 1.0e17;
            } elseif ($apf < 19.0) {
                $exponent = 1.0e18;
            } elseif ($apf < 20.0) {
                $exponent = 1.0e19;
            } elseif ($apf < 21.0) {
                $exponent = 1.0e20;
            } elseif ($apf < 22.0) {
                $exponent = 1.0e21;
            } elseif ($apf < 23.0) {
                $exponent = 1.0e22;
            } else {
                $exponent = 1.0e22;
                $remaining = (int) $apf - 22;
                if ($remaining < 0) {
                    $remaining = 0;
                }
                if ($remaining > 286) {
                    $remaining = 286;
                }
                for ($i = 0; $i < $remaining; ++$i) {
                    $exponent *= 10.0;
                }
            }
        }
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
     * 10**n for host/VM callers — thin AOT must inline the ladder in {@see roundArgv} (#35741).
     */
    public static function pow10abs(int $n): float
    {
        $nf = (float) $n;
        if ($nf < 1.0) {
            return 1.0;
        }
        if ($nf < 2.0) {
            return 10.0;
        }
        if ($nf < 3.0) {
            return 100.0;
        }
        if ($nf < 4.0) {
            return 1000.0;
        }
        if ($nf < 5.0) {
            return 10000.0;
        }
        if ($nf < 6.0) {
            return 100000.0;
        }
        if ($nf < 7.0) {
            return 1000000.0;
        }
        if ($nf < 8.0) {
            return 10000000.0;
        }
        if ($nf < 9.0) {
            return 100000000.0;
        }
        if ($nf < 10.0) {
            return 1000000000.0;
        }
        if ($nf < 11.0) {
            return 10000000000.0;
        }
        if ($nf < 12.0) {
            return 100000000000.0;
        }
        if ($nf < 13.0) {
            return 1000000000000.0;
        }
        if ($nf < 14.0) {
            return 10000000000000.0;
        }
        if ($nf < 15.0) {
            return 100000000000000.0;
        }
        if ($nf < 16.0) {
            return 1.0e15;
        }
        if ($nf < 17.0) {
            return 1.0e16;
        }
        if ($nf < 18.0) {
            return 1.0e17;
        }
        if ($nf < 19.0) {
            return 1.0e18;
        }
        if ($nf < 20.0) {
            return 1.0e19;
        }
        if ($nf < 21.0) {
            return 1.0e20;
        }
        if ($nf < 22.0) {
            return 1.0e21;
        }
        if ($nf < 23.0) {
            return 1.0e22;
        }
        $exponent = 1.0e22;
        $remaining = (int) $nf - 22;
        if ($remaining < 0) {
            $remaining = 0;
        }
        if ($remaining > 286) {
            $remaining = 286;
        }
        for ($i = 0; $i < $remaining; ++$i) {
            $exponent *= 10.0;
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
