<?php
/**
 * Issue #29587 — pecl-stats dens_* PDF family when stats advertised.
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_29587_stats_dens.php
 */
error_reporting(E_ALL);

echo 'dens_normal=', function_exists('stats_dens_normal') ? 'Y' : 'N', "\n";
echo 'count=', count(get_extension_funcs('stats') ?: []), "\n";
echo 'N01=', round(stats_dens_normal(0.0, 0.0, 1.0), 8), "\n";
echo 'exp1=', round(stats_dens_exponential(1.0, 1.0), 8), "\n";
echo 'uni=', stats_dens_uniform(0.5, 0.0, 1.0), "\n";
echo 't1=', round(stats_dens_t(0.0, 1.0), 8), "\n";
echo 'pois=', round(stats_dens_pmf_poisson(2.0, 2.0), 8), "\n";
echo 'stdev0=', var_export(@stats_dens_normal(0.0, 0.0, 0.0), true), "\n";
