--TEST--
stdlib stats cdf_* which=1 family — PECL stats parity (#29588, #29621, #29648, ext/stats)
--ENV--
PHP_COMPILER_ENABLE_STATS=1
--FILE--
<?php
echo function_exists('stats_cdf_binomial') ? 'Y' : 'N';
echo "\n";
$funcs = get_extension_funcs('stats') ?: [];
echo count($funcs) >= 48 ? 'funcs_ok' : 'funcs_bad='.count($funcs);
echo "\n";
echo round(stats_cdf_normal(0.0, 0.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_normal(1.0, 0.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_t(0.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_t(1.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_chisquare(1.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_gamma(1.0, 1.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_beta(0.5, 2.0, 2.0, 1), 8), "\n";
echo round(stats_cdf_f(1.0, 5.0, 10.0, 1), 8), "\n";
echo round(stats_cdf_poisson(3.0, 2.0, 1), 8), "\n";
echo round(stats_cdf_exponential(1.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_binomial(5.0, 10.0, 0.5, 1), 8), "\n";
echo round(stats_cdf_laplace(0.0, 0.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_cauchy(0.0, 0.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_logistic(0.0, 0.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_weibull(1.0, 1.0, 1.0, 1), 8), "\n";
echo round(stats_cdf_uniform(0.5, 0.0, 1.0, 1), 8), "\n";
var_export(@stats_cdf_weibull(1.0, 1.0, 1.0, 9));
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
0.5
0.53488057
0.85712346
0.63212056
0.62304687
0.5
0.5
0.5
0.63212056
0.5
false
