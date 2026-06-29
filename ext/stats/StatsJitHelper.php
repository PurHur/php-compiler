<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\VM\HashTable;

/**
 * stats_variance()/stats_covariance() for compiled JIT/AOT modules (#13792, php-in-PHP).
 *
 * SSOT: {@see VmStats}
 * php-src: ext/stats — PECL descriptive statistics
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
}
