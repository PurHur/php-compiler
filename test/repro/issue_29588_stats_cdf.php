<?php
/**
 * Issue #29588 — pecl-stats cdf_* which=1 family when stats advertised.
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_29588_stats_cdf.php
 */
error_reporting(E_ALL);

echo 'cdf_normal=', function_exists('stats_cdf_normal') ? 'Y' : 'N', "\n";
echo 'cdf_t=', function_exists('stats_cdf_t') ? 'Y' : 'N', "\n";
echo 'cdf_chi=', function_exists('stats_cdf_chisquare') ? 'Y' : 'N', "\n";
echo 'cdf_gamma=', function_exists('stats_cdf_gamma') ? 'Y' : 'N', "\n";
echo 'N0=', round(stats_cdf_normal(0.0, 0.0, 1.0, 1), 8), "\n";
echo 'N1=', round(stats_cdf_normal(1.0, 0.0, 1.0, 1), 8), "\n";
echo 't0=', round(stats_cdf_t(0.0, 1.0, 1), 8), "\n";
echo 't1=', round(stats_cdf_t(1.0, 1.0, 1), 8), "\n";
echo 'chi1=', round(stats_cdf_chisquare(1.0, 1.0, 1), 8), "\n";
echo 'g11=', round(stats_cdf_gamma(1.0, 1.0, 1.0, 1), 8), "\n";
echo 'badwhich=', var_export(@stats_cdf_normal(0.0, 0.0, 1.0, 0), true), "\n";
