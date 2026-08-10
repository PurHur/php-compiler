--TEST--
stdlib stats dens_* PDF family — PECL stats parity (#29587, ext/stats)
--ENV--
PHP_COMPILER_ENABLE_STATS=1
--FILE--
<?php
echo function_exists('stats_dens_normal') ? 'Y' : 'N';
echo "\n";
$funcs = get_extension_funcs('stats') ?: [];
echo count($funcs) >= 33 ? 'funcs_ok' : 'funcs_bad='.count($funcs);
echo "\n";
echo round(stats_dens_normal(0.0, 0.0, 1.0), 8), "\n";
echo round(stats_dens_exponential(1.0, 1.0), 8), "\n";
echo stats_dens_uniform(0.5, 0.0, 1.0), "\n";
echo round(stats_dens_t(0.0, 1.0), 8), "\n";
echo round(stats_dens_pmf_poisson(2.0, 2.0), 8), "\n";
echo round(stats_dens_cauchy(0.0, 0.0, 1.0), 8), "\n";
echo round(stats_dens_gamma(1.0, 1.0, 1.0), 8), "\n";
var_export(@stats_dens_normal(0.0, 0.0, 0.0));
echo "\n";
?>
--EXPECT--
Y
funcs_ok
0.39894228
0.36787944
1
0.31830989
0.27067057
0.31830989
0.36787944
false
