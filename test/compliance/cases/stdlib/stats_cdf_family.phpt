--TEST--
stdlib stats cdf_* which=1 family — PECL stats parity (#29588, ext/stats)
--ENV--
PHP_COMPILER_ENABLE_STATS=1
--FILE--
<?php
echo function_exists('stats_cdf_normal') ? 'Y' : 'N';
echo "\n";
$funcs = get_extension_funcs('stats') ?: [];
echo count($funcs) >= 38 ? 'funcs_ok' : 'funcs_bad='.count($funcs);
echo "\n";
echo round(stats_cdf_normal(0.0, 0.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_normal(1.0, 0.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_t(0.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_t(1.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_chisquare(1.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_gamma(1.0, 1.0, 1.0, 1), 8), "\n";
var_export(@stats_cdf_normal(0.0, 0.0, 1.0, 9));
echo "\n";
?>
--EXPECT--
Y
funcs_ok
0.5
0.84134474
0.5
0.75
0.68268949
0.63212056
false
