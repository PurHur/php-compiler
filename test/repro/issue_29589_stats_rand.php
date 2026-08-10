<?php
/**
 * Issue #29589 — pecl-stats rand_* when stats advertised.
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_29589_stats_rand.php
 */
error_reporting(E_ALL);

echo 'setall=', function_exists('stats_rand_setall') ? 'Y' : 'N', "\n";
echo 'getsd=', function_exists('stats_rand_getsd') ? 'Y' : 'N', "\n";
echo 'ranf=', function_exists('stats_rand_ranf') ? 'Y' : 'N', "\n";
echo 'gen_normal=', function_exists('stats_rand_gen_normal') ? 'Y' : 'N', "\n";
echo 'gen_iuniform=', function_exists('stats_rand_gen_iuniform') ? 'Y' : 'N', "\n";

var_export(stats_rand_setall(10, 20));
echo "\n";
$sd = stats_rand_getsd();
echo $sd[0], ' ', $sd[1], "\n";
stats_rand_setall(10, 20);
echo 'ranf=', round(stats_rand_ranf(), 8), "\n";
stats_rand_setall(10, 20);
echo 'iu=', stats_rand_gen_iuniform(1, 10), "\n";
stats_rand_setall(10, 20);
echo 'n0=', stats_rand_gen_normal(0.0, 0.0), "\n";
echo 'bad=', var_export(@stats_rand_gen_normal(0.0, -1.0), true), "\n";
