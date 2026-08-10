<?php
/**
 * Issue #29683 — pecl-stats stats_cdf_negative_binomial which=1.
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_29683_stats_cdf_negbin.php
 */
error_reporting(E_ALL);

echo 'negbin=', function_exists('stats_cdf_negative_binomial') ? 'Y' : 'N', "\n";
echo 'N=', round(stats_cdf_negative_binomial(3.0, 5.0, 0.5, 1), 8), "\n";
echo 'bad=', var_export(@stats_cdf_negative_binomial(3.0, 5.0, 0.5, 0), true), "\n";
