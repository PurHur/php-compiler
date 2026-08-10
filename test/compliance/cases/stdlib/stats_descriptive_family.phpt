--TEST--
stdlib stats descriptive family — PECL stats parity (#28080, ext/stats)
--ENV--
PHP_COMPILER_ENABLE_STATS=1
--FILE--
<?php
$funcs = get_extension_funcs('stats') ?: [];
echo count($funcs) >= 13 ? 'funcs_ok' : 'funcs_bad='.count($funcs);
echo "\n";
$data = [1.0, 2.0, 3.0, 4.0, 5.0];
$a = [1.0, 2.0, 3.0, 4.0];
$b = [2.0, 4.0, 6.0, 8.0];
echo round(stats_absolute_deviation($data), 3), "\n";
echo round(stats_harmonic_mean($data), 3), "\n";
var_export(stats_harmonic_mean([1.0, 0.0, 2.0]));
echo "\n";
echo stats_stat_percentile($a, 50.0), "\n";
echo round(stats_stat_correlation($a, $b), 3), "\n";
echo round(stats_skew($data), 6), "\n";
echo round(stats_kurtosis($data), 6), "\n";
echo stats_stat_powersum([1.0, 2.0, 3.0], 2.0), "\n";
echo stats_stat_innerproduct([1.0, 2.0], [3.0, 4.0]), "\n";
echo stats_stat_factorial(5), "\n";
echo stats_stat_binomial_coef(2, 5), "\n";
var_export(@stats_absolute_deviation([]));
echo "\n";
?>
--EXPECT--
funcs_ok
1.2
2.19
0
2.5
1
0
-1.3
14
11
120
10
false
