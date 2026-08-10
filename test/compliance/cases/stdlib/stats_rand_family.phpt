--TEST--
stdlib stats rand_* seedable generators — PECL stats parity (#29589, ext/stats)
--ENV--
PHP_COMPILER_ENABLE_STATS=1
--FILE--
<?php
echo function_exists('stats_rand_setall') ? 'Y' : 'N';
echo "\n";
echo function_exists('stats_rand_gen_normal') ? 'Y' : 'N';
echo "\n";
$funcs = get_extension_funcs('stats') ?: [];
echo count($funcs) >= 38 ? 'funcs_ok' : 'funcs_bad='.count($funcs);
echo "\n";
var_export(stats_rand_setall(10, 20));
echo "\n";
$sd = stats_rand_getsd();
echo $sd[0], ' ', $sd[1], "\n";
stats_rand_setall(10, 20);
echo round(stats_rand_ranf(), 8), "\n";
stats_rand_setall(10, 20);
echo stats_rand_gen_iuniform(1, 10), "\n";
stats_rand_setall(10, 20);
echo stats_rand_gen_normal(0.0, 0.0), "\n";
stats_rand_setall(10, 20);
$a = stats_rand_gen_normal(0.0, 1.0);
stats_rand_setall(10, 20);
$b = stats_rand_gen_normal(0.0, 1.0);
echo ($a === $b) ? 'det_ok' : 'det_bad';
echo "\n";
var_export(@stats_rand_gen_iuniform(2, 1));
echo "\n";
?>
--EXPECT--
Y
Y
funcs_ok
true
10 20
0.99980736
2
0
det_ok
false
