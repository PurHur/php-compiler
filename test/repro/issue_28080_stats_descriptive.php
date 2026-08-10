<?php
/**
 * Issue #28080 — pecl-stats descriptive family when stats advertised.
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_28080_stats_descriptive.php
 */
error_reporting(E_ALL);

$data = [1.0, 2.0, 3.0, 4.0, 5.0];
$a = [1.0, 2.0, 3.0, 4.0];
$b = [2.0, 4.0, 6.0, 8.0];

echo 'count=', count(get_extension_funcs('stats') ?: []), "\n";
echo 'percentile=', function_exists('stats_stat_percentile') ? 'Y' : 'N', "\n";
echo 'abs_dev=', round(stats_absolute_deviation($data), 6), "\n";
echo 'harm=', round(stats_harmonic_mean($data), 6), "\n";
echo 'harm0=', var_export(stats_harmonic_mean([1.0, 0.0, 2.0]), true), "\n";
echo 'perc50=', stats_stat_percentile($a, 50.0), "\n";
echo 'corr=', round(stats_stat_correlation($a, $b), 6), "\n";
echo 'skew=', round(stats_skew($data), 6), "\n";
echo 'kurt=', round(stats_kurtosis($data), 6), "\n";
echo 'pow2=', stats_stat_powersum([1.0, 2.0, 3.0], 2.0), "\n";
echo 'inner=', stats_stat_innerproduct([1.0, 2.0], [3.0, 4.0]), "\n";
echo 'fact=', stats_stat_factorial(5), "\n";
echo 'binom=', stats_stat_binomial_coef(2, 5), "\n";
