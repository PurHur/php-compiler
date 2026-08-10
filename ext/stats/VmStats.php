<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\ext\standard\TriggerErrorJitHelper;
use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Descriptive statistics — PECL stats algorithms in PHP (php-src ext/stats; issue #5748).
 */
final class VmStats
{
    /**
     * @return list<float>
     */
    public static function coerceNumericArray(HashTable $ht, ?Frame $frame = null): array
    {
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $v = $value->resolveIndirect();
            if (Variable::TYPE_INTEGER === $v->type) {
                $values[] = (float) $v->toInt();
                continue;
            }
            if (Variable::TYPE_FLOAT === $v->type) {
                $values[] = $v->toFloat();
                continue;
            }
            if (Variable::TYPE_STRING === $v->type && is_numeric($v->toString())) {
                $values[] = (float) $v->toString();
                continue;
            }
            if (Variable::TYPE_BOOLEAN === $v->type) {
                $values[] = (float) (int) $v->toBool();
                continue;
            }

            throw new \TypeError('stats functions expect numeric array elements');
        }

        return $values;
    }

    /**
     * PECL stats_variance — mutates array elements to float in php-src; we copy only.
     *
     * @return float|false
     */
    public static function variance(array $values, bool $sample, ?Frame $frame, string $function)
    {
        $n = \count($values);
        if (0 === $n) {
            self::warning($frame, 'The array has zero elements');

            return false;
        }
        if ($sample && 1 === $n) {
            self::warning($frame, 'The array has only 1 element');

            return false;
        }

        $mean = array_sum($values) / $n;
        $carry = 0.0;
        foreach ($values as $val) {
            $d = $val - $mean;
            $carry += $d * $d;
        }
        $divisor = $sample ? ($n - 1) : $n;

        return $carry / $divisor;
    }

    /**
     * @return float|false
     */
    public static function standardDeviation(array $values, bool $sample, ?Frame $frame, string $function)
    {
        $var = self::variance($values, $sample, $frame, $function);
        if (false === $var) {
            return false;
        }

        return \sqrt($var);
    }

    /**
     * @return float|false
     */
    public static function covariance(array $a, array $b, bool $sample, ?Frame $frame, string $function)
    {
        $na = \count($a);
        $nb = \count($b);
        if (0 === $na || 0 === $nb) {
            self::warning($frame, 'The array has zero elements');

            return false;
        }
        if ($na !== $nb) {
            self::warning($frame, 'The two arrays have unequal size');

            return false;
        }
        if ($sample && 1 === $na) {
            self::warning($frame, 'The array has only 1 element');

            return false;
        }

        $meanA = array_sum($a) / $na;
        $meanB = array_sum($b) / $nb;
        $carry = 0.0;
        for ($i = 0; $i < $na; ++$i) {
            $carry += ($a[$i] - $meanA) * ($b[$i] - $meanB);
        }
        $divisor = $sample ? ($na - 1) : $na;

        return $carry / $divisor;
    }

    /**
     * PECL stats_absolute_deviation — mean absolute deviation from the arithmetic mean.
     *
     * @param list<float> $values
     *
     * @return float|false
     */
    public static function absoluteDeviation(array $values, ?Frame $frame, string $function)
    {
        $n = \count($values);
        if (0 === $n) {
            self::warning($frame, 'The array has zero elements');

            return false;
        }
        $mean = array_sum($values) / $n;
        $absDev = 0.0;
        foreach ($values as $val) {
            $absDev += \abs($val - $mean);
        }

        return $absDev / $n;
    }

    /**
     * PECL stats_harmonic_mean — returns int 0 when any element is 0 (RETURN_LONG).
     *
     * @param list<float> $values
     *
     * @return float|int|false
     */
    public static function harmonicMean(array $values, ?Frame $frame, string $function)
    {
        $n = \count($values);
        if (0 === $n) {
            self::warning($frame, 'The array has zero elements');

            return false;
        }
        $sum = 0.0;
        foreach ($values as $val) {
            if (0 == $val) {
                return 0;
            }
            $sum += 1.0 / $val;
        }

        return $n / $sum;
    }

    /**
     * PECL stats_skew — online third central moment / σ³ (population σ).
     *
     * @param list<float> $values
     *
     * @return float|false
     */
    public static function skew(array $values, ?Frame $frame, string $function)
    {
        $n = \count($values);
        if (0 === $n) {
            self::warning($frame, 'The array has zero elements');

            return false;
        }
        $mean = array_sum($values) / $n;
        $stdDev = \sqrt(self::populationVarianceFromMean($values, $mean, false));
        $skew = 0.0;
        $i = 0;
        foreach ($values as $val) {
            $tmp = ($val - $mean) / $stdDev;
            $skew += ($tmp * $tmp * $tmp - $skew) / ($i + 1);
            ++$i;
        }

        return $skew;
    }

    /**
     * PECL stats_kurtosis — excess kurtosis (avg(z⁴) − 3).
     *
     * @param list<float> $values
     *
     * @return float|false
     */
    public static function kurtosis(array $values, ?Frame $frame, string $function)
    {
        $n = \count($values);
        if (0 === $n) {
            self::warning($frame, 'The array has zero elements');

            return false;
        }
        $mean = array_sum($values) / $n;
        $stdDev = \sqrt(self::populationVarianceFromMean($values, $mean, false));
        $avg = 0.0;
        $i = 0;
        foreach ($values as $val) {
            $tmp = ($val - $mean) / $stdDev;
            $t2 = $tmp * $tmp;
            $avg += ($t2 * $t2 - $avg) / ($i + 1);
            ++$i;
        }

        return $avg - 3.0;
    }

    /**
     * PECL stats_stat_percentile — sorts a copy; see php_stats.c.
     *
     * @param list<float> $values
     *
     * @return float|false
     */
    public static function percentile(array $values, float $perc, ?Frame $frame, string $function)
    {
        $xnum = \count($values);
        $sorted = $values;
        sort($sorted, \SORT_NUMERIC);

        $low = 0.01 * $perc * (float) $xnum;
        $upp = 0.01 * (100.0 - $perc) * (float) $xnum;
        $ilow = (int) \floor($low);
        $iupp = (int) \floor($upp);
        $val = 0.0;
        if (($ilow + $iupp) === $xnum) {
            for ($cnt = 0; $cnt < $xnum; ++$cnt) {
                if ($cnt === $ilow - 1) {
                    $val = ($sorted[$cnt] + $sorted[$cnt + 1]) / 2.0;
                    break;
                }
            }
        } else {
            for ($cnt = 0; $cnt < $xnum; ++$cnt) {
                if ($cnt === $ilow) {
                    $val = $sorted[$cnt];
                    break;
                }
            }
        }

        return $val;
    }

    /**
     * PECL stats_stat_correlation — Pearson r; undefined when either variance is 0.
     *
     * @param list<float> $a
     * @param list<float> $b
     *
     * @return float|false
     */
    public static function correlation(array $a, array $b, ?Frame $frame, string $function)
    {
        $xnum = \count($a);
        $ynum = \count($b);
        if ($xnum !== $ynum) {
            self::warning($frame, 'Unequal number of X and Y coordinates');

            return false;
        }
        if ($xnum < 2) {
            self::warning($frame, 'Correlation requires at least 2 data points');

            return false;
        }
        $mx = array_sum($a) / $xnum;
        $my = array_sum($b) / $ynum;
        $vx = 0.0;
        $vy = 0.0;
        $cc = 0.0;
        for ($i = 0; $i < $xnum; ++$i) {
            $dx = $a[$i] - $mx;
            $dy = $b[$i] - $my;
            $vx += $dx * $dx;
            $vy += $dy * $dy;
            $cc += $dx * $dy;
        }
        if (0.0 === $vx || 0.0 === $vy) {
            self::warning($frame, 'Correlation is undefined when one or both arrays have zero variance');

            return false;
        }
        $rr = $cc / \sqrt($vx * $vy);
        if ($rr > 1.0) {
            $rr = 1.0;
        }
        if ($rr < -1.0) {
            $rr = -1.0;
        }

        return $rr;
    }

    /**
     * PECL stats_stat_powersum — Σ x^power (warns when both value and power are 0).
     *
     * @param list<float> $values
     *
     * @return float|false
     */
    public static function powersum(array $values, float $power, ?Frame $frame, string $function)
    {
        $sum = 0.0;
        foreach ($values as $val) {
            if (0.0 === $val && 0.0 === $power) {
                self::warning($frame, 'Both value and power are zero');
                continue;
            }
            $sum += $val ** $power;
        }

        return $sum;
    }

    /**
     * PECL stats_stat_innerproduct — Σ aᵢ·bᵢ.
     *
     * @param list<float> $a
     * @param list<float> $b
     *
     * @return float|false
     */
    public static function innerproduct(array $a, array $b, ?Frame $frame, string $function)
    {
        if (\count($a) !== \count($b)) {
            self::warning($frame, 'Unequal number of X and Y coordinates');

            return false;
        }
        $sum = 0.0;
        $n = \count($a);
        for ($i = 0; $i < $n; ++$i) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /** PECL stats_stat_factorial — product 2..n; stops on INF. */
    public static function factorial(int $n): float
    {
        $f = 1.0;
        for ($i = $n; $i >= 2; --$i) {
            $f *= $i;
            if (\is_infinite($f)) {
                break;
            }
        }

        return $f;
    }

    /** PECL stats_stat_binomial_coef — C(n, x) via multiplicative formula. */
    public static function binomialCoef(int $x, int $n): float
    {
        $bc = 1.0;
        for ($i = 0; $i < $x; ++$i) {
            $bc = ($bc * ($n - $i)) / ($i + 1);
        }

        return $bc;
    }

    /**
     * @param list<float> $values
     */
    private static function populationVarianceFromMean(array $values, float $mean, bool $sample): float
    {
        $n = \count($values);
        $vr = 0.0;
        foreach ($values as $val) {
            $d = $val - $mean;
            $vr += $d * $d;
        }
        $denom = $sample ? ($n - 1) : $n;

        return $vr / $denom;
    }

    private static function warning(?Frame $frame, string $message): void
    {
        if (null !== $frame?->vmContext) {
            $frame->vmContext->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );

            return;
        }
        TriggerErrorJitHelper::warning($message);
    }
}
