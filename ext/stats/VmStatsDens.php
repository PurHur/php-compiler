<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/**
 * PECL stats dens_* / dens_pmf_* PDFs — php_stats.c algorithms (#29587).
 */
final class VmStatsDens
{
    private const PI = \M_PI;

    /**
     * Lanczos approximation for log Γ(x) — matches libm lgamma closely enough for dens fixtures.
     */
    public static function logGamma(float $x): float
    {
        if ($x <= 0.0) {
            return \NAN;
        }
        // Reflection for (0,1)
        if ($x < 0.5) {
            return \log(self::PI) - \log(\sin(self::PI * $x)) - self::logGamma(1.0 - $x);
        }
        static $c = [
            0.99999999999980993,
            676.5203681218851,
            -1259.1392167224028,
            771.32342877765313,
            -176.61502916214059,
            12.507343278686905,
            -0.13857109526572012,
            9.984369654078991e-6,
            1.5056327351493116e-7,
        ];
        $z = $x - 1.0;
        $a = $c[0];
        for ($i = 1; $i < 9; ++$i) {
            $a += $c[$i] / ($z + $i);
        }
        $t = $z + 7.5;

        return \log(\sqrt(2.0 * self::PI)) + (($z + 0.5) * \log($t)) - $t + \log($a);
    }

    /** @return float|false */
    public static function normal(float $x, float $ave, float $stdev, ?Frame $frame)
    {
        if (0.0 == $stdev) {
            self::warning($frame, 'stdev is 0.0');

            return false;
        }
        $z = ($x - $ave) / $stdev;

        return (1.0 / ($stdev * \sqrt(2.0 * self::PI))) * \exp(-0.5 * $z * $z);
    }

    /** @return float|false */
    public static function cauchy(float $x, float $ave, float $stdev, ?Frame $frame)
    {
        if (0.0 == $stdev) {
            self::warning($frame, 'stdev is 0.0');

            return false;
        }
        $z = ($x - $ave) / $stdev;

        return 1.0 / ($stdev * self::PI * (1.0 + ($z * $z)));
    }

    /** @return float|false */
    public static function laplace(float $x, float $ave, float $stdev, ?Frame $frame)
    {
        if (0.0 == $stdev) {
            self::warning($frame, 'stdev is 0.0');

            return false;
        }
        $z = \abs(($x - $ave) / $stdev);

        return (1.0 / (2.0 * $stdev)) * \exp(-$z);
    }

    /** @return float|false */
    public static function logistic(float $x, float $ave, float $stdev, ?Frame $frame)
    {
        if (0.0 == $stdev) {
            self::warning($frame, 'stdev is 0.0');

            return false;
        }
        $z = \exp(($x - $ave) / $stdev);

        return $z / ($stdev * ((1.0 + $z) ** 2.0));
    }

    /** @return float|false */
    public static function beta(float $x, float $a, float $b, ?Frame $frame)
    {
        $beta = 1.0 / \exp(self::logGamma($a) + self::logGamma($b) - self::logGamma($a + $b));

        return $beta * ($x ** ($a - 1.0)) * ((1.0 - $x) ** ($b - 1.0));
    }

    /** @return float|false */
    public static function weibull(float $x, float $a, float $b, ?Frame $frame)
    {
        if (0.0 == $b) {
            self::warning($frame, 'b is 0.0');

            return false;
        }

        return ($a / $b) * (($x / $b) ** ($a - 1.0)) * \exp(-(($x / $b) ** $a));
    }

    /** @return float|false */
    public static function uniform(float $x, float $a, float $b, ?Frame $frame)
    {
        if ($a == $b) {
            self::warning($frame, \sprintf('b == a == %16.6E', $a));

            return false;
        }
        if ($x <= $b && $x >= $a) {
            return 1.0 / ($b - $a);
        }

        return 0.0;
    }

    /** @return float|false */
    public static function chisquare(float $x, float $dfr, ?Frame $frame)
    {
        $e = $dfr / 2.0;
        $z = (($e - 1.0) * \log($x)) - (($x / 2.0) + ($e * \log(2.0)) + self::logGamma($e));

        return \exp($z);
    }

    /** @return float|false */
    public static function t(float $x, float $dfr, ?Frame $frame)
    {
        if (0.0 == $dfr) {
            self::warning($frame, 'dfr == 0.0');

            return false;
        }
        $e = $dfr / 2.0;
        $f = $e + 0.5;
        $fac1 = self::logGamma($f);
        $fac2 = $f * \log(1.0 + ($x * $x) / $dfr);
        $fac3 = self::logGamma($e) + 0.5 * \log($dfr * self::PI);

        return \exp($fac1 - ($fac2 + $fac3));
    }

    /** @return float|false */
    public static function gamma(float $x, float $shape, float $scale, ?Frame $frame)
    {
        if (0.0 == $scale) {
            self::warning($frame, 'scale == 0.0');

            return false;
        }
        $z = (($shape - 1.0) * \log($x)) - (($x / $scale) + self::logGamma($shape) + ($shape * \log($scale)));

        return \exp($z);
    }

    /** @return float|false */
    public static function exponential(float $x, float $scale, ?Frame $frame)
    {
        if (0.0 == $scale) {
            self::warning($frame, 'scale == 0.0');

            return false;
        }
        if ($x < 0.0) {
            return 0.0;
        }

        return \exp(-$x / $scale) / $scale;
    }

    /** @return float|false */
    public static function f(float $x, float $dfr1, float $dfr2, ?Frame $frame)
    {
        $efr1 = $dfr1 / 2.0;
        $efr2 = $dfr2 / 2.0;
        $fac1 = ($efr1 - 1.0) * \log($x);
        $fac2 = ($efr1 + $efr2) * \log($dfr2 + ($dfr1 * $x));
        $fac3 = ($efr1 * \log($dfr1)) + ($efr2 * \log($dfr2));
        $fac4 = self::logGamma($efr1) + self::logGamma($efr2) - self::logGamma($efr1 + $efr2);
        $z = ($fac1 + $fac3) - ($fac2 + $fac4);

        return \exp($z);
    }

    /** @return float|false */
    public static function pmfBinomial(float $x, float $n, float $pi, ?Frame $frame)
    {
        if ((0.0 == $x && 0.0 == $n) || (0.0 == $pi && 0.0 == $x)
            || (0.0 == (1.0 - $pi) && 0.0 == ($n - $x))) {
            self::warning($frame, \sprintf(
                'Params leading to pow(0, 0). x:%16.6E n:%16.6E pi:%16.6E',
                $x,
                $n,
                $pi
            ));

            return false;
        }

        return self::binom($x, $n) * ($pi ** $x) * ((1.0 - $pi) ** ($n - $x));
    }

    /** @return float|false */
    public static function pmfPoisson(float $x, float $lb, ?Frame $frame)
    {
        $z = ($x * \log($lb)) - ($lb + self::logGamma($x + 1.0));

        return \exp($z);
    }

    /** @return float|false */
    public static function pmfNegativeBinomial(float $x, float $n, float $pi, ?Frame $frame)
    {
        if ((0.0 == $pi && 0.0 == $n) || (0.0 == (1.0 - $pi) && 0.0 == $x)) {
            self::warning($frame, \sprintf(
                'Params leading to pow(0, 0). x:%16.6E n:%16.6E pi:%16.6E',
                $x,
                $n,
                $pi
            ));

            return false;
        }

        return self::binom($x, $n + $x - 1.0) * ($pi ** $n) * ((1.0 - $pi) ** $x);
    }

    /** @return float|false */
    public static function pmfHypergeometric(float $n1, float $n2, float $N1, float $N2, ?Frame $frame)
    {
        if ((int) ($n1 + $n2) >= (int) ($N1 + $N2)) {
            self::warning($frame, 'possible division by zero - n1+n2 >= N1+N2');
        }

        return self::binom($n1, $N1) * self::binom($n2, $N2) / self::binom($n1 + $n2, $N1 + $N2);
    }

    /** Dens op codes for {@see StatsJitHelper::dens}. */
    public const OP_NORMAL = 1;
    public const OP_CAUCHY = 2;
    public const OP_LAPLACE = 3;
    public const OP_LOGISTIC = 4;
    public const OP_BETA = 5;
    public const OP_WEIBULL = 6;
    public const OP_UNIFORM = 7;
    public const OP_CHISQUARE = 8;
    public const OP_T = 9;
    public const OP_GAMMA = 10;
    public const OP_EXPONENTIAL = 11;
    public const OP_F = 12;
    public const OP_PMF_BINOMIAL = 13;
    public const OP_PMF_POISSON = 14;
    public const OP_PMF_NEGBIN = 15;
    public const OP_PMF_HYPER = 16;

    /** @return float|false */
    public static function dispatch(int $op, float $a, float $b, float $c, float $d, ?Frame $frame)
    {
        return match ($op) {
            self::OP_NORMAL => self::normal($a, $b, $c, $frame),
            self::OP_CAUCHY => self::cauchy($a, $b, $c, $frame),
            self::OP_LAPLACE => self::laplace($a, $b, $c, $frame),
            self::OP_LOGISTIC => self::logistic($a, $b, $c, $frame),
            self::OP_BETA => self::beta($a, $b, $c, $frame),
            self::OP_WEIBULL => self::weibull($a, $b, $c, $frame),
            self::OP_UNIFORM => self::uniform($a, $b, $c, $frame),
            self::OP_CHISQUARE => self::chisquare($a, $b, $frame),
            self::OP_T => self::t($a, $b, $frame),
            self::OP_GAMMA => self::gamma($a, $b, $c, $frame),
            self::OP_EXPONENTIAL => self::exponential($a, $b, $frame),
            self::OP_F => self::f($a, $b, $c, $frame),
            self::OP_PMF_BINOMIAL => self::pmfBinomial($a, $b, $c, $frame),
            self::OP_PMF_POISSON => self::pmfPoisson($a, $b, $frame),
            self::OP_PMF_NEGBIN => self::pmfNegativeBinomial($a, $b, $c, $frame),
            self::OP_PMF_HYPER => self::pmfHypergeometric($a, $b, $c, $d, $frame),
            default => false,
        };
    }

    private static function binom(float $x, float $n): float
    {
        $s = 1.0;
        for ($i = 0; $i < $x; ++$i) {
            $di = (float) $i;
            $s = ($s * ($n - $di)) / ($di + 1.0);
        }

        return $s;
    }

    private static function warning(?Frame $frame, string $message): void
    {
        VmStats::triggerWarning($frame, $message);
    }
}
