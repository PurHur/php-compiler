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

    /**
     * dens_* dispatcher — op codes in {@see VmStatsDens}.
     * Returns NAN on failure (boxed as false by JitStats).
     */
    public static function dens(int $op, float $a, float $b, float $c, float $d): float
    {
        $result = VmStatsDens::dispatch($op, $a, $b, $c, $d, null);

        return false === $result ? \NAN : $result;
    }

    /**
     * cdf_* dispatcher — op codes in {@see VmStatsCdf}.
     * Returns NAN on failure (boxed as false by JitStats).
     */
    public static function cdf(int $op, int $which, float $a, float $b, float $c): float
    {
        $result = VmStatsCdf::dispatch($op, $which, $a, $b, $c, null);

        return false === $result ? \NAN : $result;
    }

    public static function randSetall(int $iseed1, int $iseed2): bool
    {
        return VmStatsRand::setall($iseed1, $iseed2);
    }

    public static function randGetsd(): HashTable
    {
        return VmStatsRand::getsdHashTable();
    }

    public static function randRanf(): float
    {
        return VmStatsRand::ranf();
    }

    public static function randGenNormal(float $av, float $sd): float
    {
        $result = VmStatsRand::genNormal($av, $sd, null);

        return false === $result ? \NAN : $result;
    }

    /** Returns float or NAN on failure — JIT boxes like other stats results. */
    public static function randGenIuniform(int $low, int $high): float
    {
        $result = VmStatsRand::genIuniform($low, $high, null);

        return false === $result ? \NAN : (float) $result;
    }

    /**
     * Remaining rand_gen_* via op codes (#29622).
     * Returns NAN on failure.
     */
    public static function randGen(int $op, float $a, float $b): float
    {
        $result = match ($op) {
            VmStatsRand::OP_GEN_BETA => VmStatsRand::genBeta($a, $b, null),
            VmStatsRand::OP_GEN_EXPONENTIAL => VmStatsRand::genExponential($a, null),
            VmStatsRand::OP_GEN_GAMMA => VmStatsRand::genGamma($a, $b, null),
            VmStatsRand::OP_GEN_CHISQUARE => VmStatsRand::genChisquare($a, null),
            VmStatsRand::OP_GEN_F => VmStatsRand::genF($a, $b, null),
            VmStatsRand::OP_GEN_FUNIFORM => VmStatsRand::genFuniform($a, $b, null),
            default => false,
        };

        return false === $result ? \NAN : $result;
    }

    /** Returns float or NAN on failure — JIT boxes like iuniform. */
    public static function randIbinomial(int $n, float $pp): float
    {
        $result = VmStatsRand::ibinomial($n, $pp, null);

        return false === $result ? \NAN : (float) $result;
    }

    public static function randPhraseToSeeds(string $phrase): HashTable
    {
        return VmStatsRand::phraseToSeedsHashTable($phrase);
    }
}
