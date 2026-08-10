--TEST--
stdlib stats rand_* remaining generators — PECL stats parity (#29622, ext/stats)
--ENV--
PHP_COMPILER_ENABLE_STATS=1
--FILE--
<?php
echo function_exists('stats_rand_gen_beta') ? 'Y' : 'N';
echo "\n";
echo function_exists('stats_rand_phrase_to_seeds') ? 'Y' : 'N';
echo "\n";
$funcs = get_extension_funcs('stats') ?: [];
echo count($funcs) >= 46 ? 'funcs_ok' : 'funcs_bad='.count($funcs);
echo "\n";
$seeds = stats_rand_phrase_to_seeds('hello');
echo $seeds[0], ' ', $seeds[1], "\n";
stats_rand_setall(10, 20);
$b1 = stats_rand_gen_beta(2.0, 2.0);
stats_rand_setall(10, 20);
$b2 = stats_rand_gen_beta(2.0, 2.0);
echo ($b1 === $b2 && $b1 > 0.0 && $b1 < 1.0) ? 'beta_ok' : 'beta_bad';
echo "\n";
stats_rand_setall(10, 20);
echo round(stats_rand_gen_exponential(1.0), 8), "\n";
stats_rand_setall(10, 20);
$g1 = stats_rand_gen_gamma(1.0, 0.5);
stats_rand_setall(10, 20);
$g2 = stats_rand_gen_gamma(1.0, 0.5);
echo ($g1 === $g2 && $g1 > 0.0) ? 'gamma_ok' : 'gamma_bad';
echo "\n";
var_export(@stats_rand_gen_exponential(-1.0));
echo "\n";
?>
--EXPECT--
Y
Y
funcs_ok
605576577 923149679
beta_ok
0.02397895
gamma_ok
false
