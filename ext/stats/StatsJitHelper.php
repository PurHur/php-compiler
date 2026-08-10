<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\VM\HashTable;

/**
 * stats_* helpers for compiled JIT/AOT modules (#13792 / #28080, php-in-PHP).
 *
 * SSOT: {@see VmStats}
 * php-src: pecl-math-stats php_stats.c — descriptive statistics
 */
final class StatsJitHelper
{
    public static function variance(HashTable $ht, bool $sample): float
    {
        $values = VmStats::coerceNumericArray($ht);
        $result = VmStats::variance($values, $sample, null, 'stats_variance');

        return false === $result ? \NAN : $result;
    }

    public static function covariance(HashTable $htA, HashTable $htB, bool $sample): float
    {
        $valuesA = VmStats::coerceNumericArray($htA);
        $valuesB = VmStats::coerceNumericArray($htB);
        $result = VmStats::covariance($valuesA, $valuesB, $sample, null, 'stats_covariance');

        return false === $result ? \NAN : $result;
    }

    public static function absoluteDeviation(HashTable $ht): float
    {
        $values = VmStats::coerceNumericArray($ht);
        $result = VmStats::absoluteDeviation($values, null, 'stats_absolute_deviation');

        return false === $result ? \NAN : $result;
    }

    public static function harmonicMean(HashTable $ht): float
    {
        $values = VmStats::coerceNumericArray($ht);
        $result = VmStats::harmonicMean($values, null, 'stats_harmonic_mean');
        if (false === $result) {
            return \NAN;
        }

        return (float) $result;
    }

    public static function skew(HashTable $ht): float
    {
        $values = VmStats::coerceNumericArray($ht);
        $result = VmStats::skew($values, null, 'stats_skew');

        return false === $result ? \NAN : $result;
    }

    public static function kurtosis(HashTable $ht): float
    {
        $values = VmStats::coerceNumericArray($ht);
        $result = VmStats::kurtosis($values, null, 'stats_kurtosis');

        return false === $result ? \NAN : $result;
    }

    public static function percentile(HashTable $ht, float $perc): float
    {
        $values = VmStats::coerceNumericArray($ht);
        $result = VmStats::percentile($values, $perc, null, 'stats_stat_percentile');

        return false === $result ? \NAN : $result;
    }

    public static function correlation(HashTable $htA, HashTable $htB): float
    {
        $valuesA = VmStats::coerceNumericArray($htA);
        $valuesB = VmStats::coerceNumericArray($htB);
        $result = VmStats::correlation($valuesA, $valuesB, null, 'stats_stat_correlation');

        return false === $result ? \NAN : $result;
    }

    public static function powersum(HashTable $ht, float $power): float
    {
        $values = VmStats::coerceNumericArray($ht);
        $result = VmStats::powersum($values, $power, null, 'stats_stat_powersum');

        return false === $result ? \NAN : $result;
    }

    public static function innerproduct(HashTable $htA, HashTable $htB): float
    {
        $valuesA = VmStats::coerceNumericArray($htA);
        $valuesB = VmStats::coerceNumericArray($htB);
        $result = VmStats::innerproduct($valuesA, $valuesB, null, 'stats_stat_innerproduct');

        return false === $result ? \NAN : $result;
    }

    public static function factorial(int $n): float
    {
        return VmStats::factorial($n);
    }

    public static function binomialCoef(int $x, int $n): float
    {
        return VmStats::binomialCoef($x, $n);
    }
}
