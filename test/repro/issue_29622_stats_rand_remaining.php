<?php
/**
 * Issue #29622 — pecl-stats remaining rand_gen_* + phrase_to_seeds when stats advertised.
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_29622_stats_rand_remaining.php
 */
error_reporting(E_ALL);

echo 'beta=', function_exists('stats_rand_gen_beta') ? 'Y' : 'N', "\n";
echo 'exp=', function_exists('stats_rand_gen_exponential') ? 'Y' : 'N', "\n";
echo 'gamma=', function_exists('stats_rand_gen_gamma') ? 'Y' : 'N', "\n";
echo 'phrase=', function_exists('stats_rand_phrase_to_seeds') ? 'Y' : 'N', "\n";

$seeds = stats_rand_phrase_to_seeds('hello');
echo 'phrase=', $seeds[0], ' ', $seeds[1], "\n";
$empty = stats_rand_phrase_to_seeds('');
echo 'empty=', $empty[0], ' ', $empty[1], "\n";

stats_rand_setall(10, 20);
$b1 = stats_rand_gen_beta(2.0, 2.0);
stats_rand_setall(10, 20);
$b2 = stats_rand_gen_beta(2.0, 2.0);
echo ($b1 === $b2 && $b1 > 0.0 && $b1 < 1.0) ? 'beta_ok' : 'beta_bad', "\n";

stats_rand_setall(10, 20);
$e1 = stats_rand_gen_exponential(1.0);
stats_rand_setall(10, 20);
$e2 = stats_rand_gen_exponential(1.0);
echo ($e1 === $e2 && $e1 >= 0.0) ? 'exp_ok' : 'exp_bad', "\n";
echo 'exp_v=', round($e1, 8), "\n";

stats_rand_setall(10, 20);
$g1 = stats_rand_gen_gamma(1.0, 0.5);
stats_rand_setall(10, 20);
$g2 = stats_rand_gen_gamma(1.0, 0.5);
echo ($g1 === $g2 && $g1 > 0.0) ? 'gamma_ok' : 'gamma_bad', "\n";

echo 'bad=', var_export(@stats_rand_gen_beta(0.0, 1.0), true), "\n";
