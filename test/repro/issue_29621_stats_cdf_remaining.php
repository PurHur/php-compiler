<?php
/**
 * Issue #29621 — pecl-stats remaining cdf_* which=1 when stats advertised.
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_29621_stats_cdf_remaining.php
 */
error_reporting(E_ALL);

echo 'cdf_beta=', function_exists('stats_cdf_beta') ? 'Y' : 'N', "\n";
echo 'cdf_f=', function_exists('stats_cdf_f') ? 'Y' : 'N', "\n";
echo 'cdf_pois=', function_exists('stats_cdf_poisson') ? 'Y' : 'N', "\n";
echo 'cdf_exp=', function_exists('stats_cdf_exponential') ? 'Y' : 'N', "\n";
echo 'B=', round(stats_cdf_beta(0.5, 2.0, 2.0, 1), 8), "\n";
echo 'F=', round(stats_cdf_f(1.0, 5.0, 10.0, 1), 8), "\n";
echo 'P=', round(stats_cdf_poisson(3.0, 2.0, 1), 8), "\n";
echo 'E=', round(stats_cdf_exponential(1.0, 1.0, 1), 8), "\n";
echo 'badwhich=', var_export(@stats_cdf_f(1.0, 5.0, 10.0, 0), true), "\n";
