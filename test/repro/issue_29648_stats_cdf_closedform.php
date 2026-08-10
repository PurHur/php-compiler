<?php
/**
 * Issue #29648 — pecl-stats remaining cdf_* which=1 (binomial + closed-form).
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_29648_stats_cdf_closedform.php
 */
error_reporting(E_ALL);

echo 'cdf_bin=', function_exists('stats_cdf_binomial') ? 'Y' : 'N', "\n";
echo 'cdf_lap=', function_exists('stats_cdf_laplace') ? 'Y' : 'N', "\n";
echo 'cdf_cau=', function_exists('stats_cdf_cauchy') ? 'Y' : 'N', "\n";
echo 'cdf_wei=', function_exists('stats_cdf_weibull') ? 'Y' : 'N', "\n";
echo 'cdf_log=', function_exists('stats_cdf_logistic') ? 'Y' : 'N', "\n";
echo 'cdf_uni=', function_exists('stats_cdf_uniform') ? 'Y' : 'N', "\n";
echo 'B=', round(stats_cdf_binomial(5.0, 10.0, 0.5, 1), 8), "\n";
echo 'L=', round(stats_cdf_laplace(1.0, 0.0, 1.0, 1), 8), "\n";
echo 'C=', round(stats_cdf_cauchy(1.0, 0.0, 1.0, 1), 8), "\n";
echo 'G=', round(stats_cdf_logistic(1.0, 0.0, 1.0, 1), 8), "\n";
echo 'W=', round(stats_cdf_weibull(1.0, 1.0, 1.0, 1), 8), "\n";
echo 'U=', round(stats_cdf_uniform(0.25, 0.0, 1.0, 1), 8), "\n";
echo 'badwhich=', var_export(@stats_cdf_laplace(0.0, 0.0, 1.0, 0), true), "\n";
