<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/**
 * PECL stats_cdf_* cumulative distributions — which=1 (CDF) first (#29588).
 *
 * php-src: pecl-math-stats php_stats.c (DCDFLIB cdfnor/cdft/cdfchi/cdfgam).
 * Algorithms are PHP ports of the standard Abramowitz–Stegun reductions;
 * inverse / which≥2 modes return PECL-style Computation Error for now.
 */
final class VmStatsCdf
{
    public const OP_NORMAL = 1;
    public const OP_T = 2;
    public const OP_CHISQUARE = 3;
    public const OP_GAMMA = 4;

    private const PI = \M_PI;

    /**
     * @return float|false
     */
    public static function dispatch(
        int $op,
        int $which,
        float $a,
        float $b,
        float $c,
        ?Frame $frame
    ) {
        return match ($op) {
            self::OP_NORMAL => self::normal($a, $b, $c, $which, $frame),
            self::OP_T => self::t($a, $b, $which, $frame),
            self::OP_CHISQUARE => self::chisquare($a, $b, $which, $frame),
            self::OP_GAMMA => self::gamma($a, $b, $c, $which, $frame),
            default => false,
        };
    }

    /** @return float|false */
    public static function normal(float $par1, float $par2, float $par3, int $which, ?Frame $frame)
    {
        if ($which < 1 || $which > 4) {
            self::warning($frame, 'Fourth parameter should be in the 1..4 range');

            return false;
        }
        if (1 !== $which) {
            return self::computationError($frame);
        }
        // which=1: P from X, MEAN, SD
        $x = $par1;
        $mean = $par2;
        $sd = $par3;
        if ($sd <= 0.0) {
            return self::computationError($frame);
        }

        return self::standardNormalCdf(($x - $mean) / $sd);
    }

    /** @return float|false */
    public static function t(float $par1, float $par2, int $which, ?Frame $frame)
    {
        if ($which < 1 || $which > 3) {
            self::warning($frame, 'Third parameter should be in the 1..3 range');

            return false;
        }
        if (1 !== $which) {
            return self::computationError($frame);
        }
        $t = $par1;
        $df = $par2;
        if ($df <= 0.0) {
            return self::computationError($frame);
        }

        return self::studentTCdf($t, $df);
    }

    /** @return float|false */
    public static function chisquare(float $par1, float $par2, int $which, ?Frame $frame)
    {
        if ($which < 1 || $which > 3) {
            self::warning($frame, 'Third parameter should be in the 1..3 range');

            return false;
        }
        if (1 !== $which) {
            return self::computationError($frame);
        }
        $x = $par1;
        $df = $par2;
        if ($df <= 0.0 || $x < 0.0) {
            return self::computationError($frame);
        }

        return self::regularizedLowerGamma($df / 2.0, $x / 2.0);
    }

    /** @return float|false */
    public static function gamma(float $par1, float $par2, float $par3, int $which, ?Frame $frame)
    {
        if ($which < 1 || $which > 4) {
            self::warning($frame, 'Fourth parameter should be in the 1..4 range');

            return false;
        }
        if (1 !== $which) {
            return self::computationError($frame);
        }
        // which=1: P from X, SHAPE, SCALE (PECL converts scale→rate=1/scale for DCDFLIB)
        $x = $par1;
        $shape = $par2;
        $scale = $par3;
        if ($shape <= 0.0 || $scale <= 0.0 || $x < 0.0) {
            return self::computationError($frame);
        }

        return self::regularizedLowerGamma($shape, $x / $scale);
    }

    public static function standardNormalCdf(float $z): float
    {
        return 0.5 * (1.0 + self::erf($z / \sqrt(2.0)));
    }

    /**
     * Student's t CDF via incomplete beta (Abramowitz & Stegun 26.5.27).
     */
    public static function studentTCdf(float $t, float $df): float
    {
        $x = $df / ($df + ($t * $t));
        $ib = self::regularizedIncompleteBeta($x, $df / 2.0, 0.5);
        if ($t >= 0.0) {
            return 1.0 - 0.5 * $ib;
        }

        return 0.5 * $ib;
    }

    /** Abramowitz–Stegun 7.1.26 erf approximation. */
    public static function erf(float $x): float
    {
        $sign = $x < 0.0 ? -1.0 : 1.0;
        $ax = \abs($x);
        $t = 1.0 / (1.0 + 0.3275911 * $ax);
        $y = 1.0 - (((((1.061405429 * $t) - 1.453152027) * $t + 1.421413741) * $t
            - 0.284496736) * $t + 0.254829592) * $t * \exp(-$ax * $ax);

        return $sign * $y;
    }

    /**
     * Regularized lower incomplete gamma P(a,x) = γ(a,x)/Γ(a).
     *
     * Series for x < a+1, continued fraction otherwise.
     */
    public static function regularizedLowerGamma(float $a, float $x): float
    {
        if ($x < 0.0 || $a <= 0.0) {
            return \NAN;
        }
        if (0.0 == $x) {
            return 0.0;
        }
        if ($x < $a + 1.0) {
            return self::gammaSeries($a, $x);
        }

        return 1.0 - self::gammaContinuedFraction($a, $x);
    }

    private static function gammaSeries(float $a, float $x): float
    {
        $gln = VmStatsDens::logGamma($a);
        $ap = $a;
        $sum = 1.0 / $a;
        $del = $sum;
        for ($n = 1; $n <= 200; ++$n) {
            $ap += 1.0;
            $del *= $x / $ap;
            $sum += $del;
            if (\abs($del) < \abs($sum) * 1e-14) {
                return $sum * \exp(-$x + $a * \log($x) - $gln);
            }
        }

        return $sum * \exp(-$x + $a * \log($x) - $gln);
    }

    private static function gammaContinuedFraction(float $a, float $x): float
    {
        $gln = VmStatsDens::logGamma($a);
        $b = $x + 1.0 - $a;
        $c = 1e30;
        $d = 1.0 / $b;
        $h = $d;
        for ($i = 1; $i <= 200; ++$i) {
            $an = -$i * ($i - $a);
            $b += 2.0;
            $d = $an * $d + $b;
            if (\abs($d) < 1e-30) {
                $d = 1e-30;
            }
            $c = $b + $an / $c;
            if (\abs($c) < 1e-30) {
                $c = 1e-30;
            }
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;
            if (\abs($del - 1.0) < 1e-14) {
                break;
            }
        }

        return \exp(-$x + $a * \log($x) - $gln) * $h;
    }

    /**
     * Regularized incomplete beta I_x(a,b).
     */
    public static function regularizedIncompleteBeta(float $x, float $a, float $b): float
    {
        if ($x <= 0.0) {
            return 0.0;
        }
        if ($x >= 1.0) {
            return 1.0;
        }
        $lbeta = VmStatsDens::logGamma($a) + VmStatsDens::logGamma($b)
            - VmStatsDens::logGamma($a + $b);
        $front = \exp(\log($x) * $a + \log(1.0 - $x) * $b - $lbeta) / $a;
        if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
            return $front * self::betaContinuedFraction($x, $a, $b);
        }

        return 1.0 - (\exp(\log($x) * $a + \log(1.0 - $x) * $b - $lbeta) / $b)
            * self::betaContinuedFraction(1.0 - $x, $b, $a);
    }

    private static function betaContinuedFraction(float $x, float $a, float $b): float
    {
        $m = 0;
        $qab = $a + $b;
        $qap = $a + 1.0;
        $qam = $a - 1.0;
        $c = 1.0;
        $d = 1.0 - $qab * $x / $qap;
        if (\abs($d) < 1e-30) {
            $d = 1e-30;
        }
        $d = 1.0 / $d;
        $h = $d;
        for ($m = 1; $m <= 200; ++$m) {
            $m2 = 2 * $m;
            $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
            $d = 1.0 + $aa * $d;
            if (\abs($d) < 1e-30) {
                $d = 1e-30;
            }
            $c = 1.0 + $aa / $c;
            if (\abs($c) < 1e-30) {
                $c = 1e-30;
            }
            $d = 1.0 / $d;
            $h *= $d * $c;
            $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
            $d = 1.0 + $aa * $d;
            if (\abs($d) < 1e-30) {
                $d = 1e-30;
            }
            $c = 1.0 + $aa / $c;
            if (\abs($c) < 1e-30) {
                $c = 1e-30;
            }
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;
            if (\abs($del - 1.0) < 1e-14) {
                break;
            }
        }

        return $h;
    }

    /** @return false */
    private static function computationError(?Frame $frame): bool
    {
        self::warning($frame, 'Computation Error');

        return false;
    }

    private static function warning(?Frame $frame, string $message): void
    {
        VmStats::triggerWarning($frame, $message);
    }
}
