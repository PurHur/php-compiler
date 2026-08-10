--TEST--
stdlib stats rand_* remaining generators — PECL stats parity (#29622, #29649, ext/stats)
--ENV--
PHP_COMPILER_ENABLE_STATS=1
--FILE--
<?php
echo function_exists('stats_rand_gen_beta') ? 'Y' : 'N';
echo "\n";
echo function_exists('stats_rand_gen_chisquare') ? 'Y' : 'N';
echo "\n";
echo function_exists('stats_rand_ibinomial') ? 'Y' : 'N';
echo "\n";
$funcs = get_extension_funcs('stats') ?: [];
echo count($funcs) >= 50 ? 'funcs_ok' : 'funcs_bad='.count($funcs);
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
stats_rand_setall(10, 20);
$c1 = stats_rand_gen_chisquare(2.0);
stats_rand_setall(10, 20);
$c2 = stats_rand_gen_chisquare(2.0);
echo ($c1 === $c2 && $c1 > 0.0) ? 'chi_ok' : 'chi_bad';
echo "\n";
echo round($c1, 8), "\n";
stats_rand_setall(10, 20);
$f1 = stats_rand_gen_f(5.0, 10.0);
stats_rand_setall(10, 20);
$f2 = stats_rand_gen_f(5.0, 10.0);
echo ($f1 === $f2 && $f1 > 0.0) ? 'f_ok' : 'f_bad';
echo "\n";
stats_rand_setall(10, 20);
$u1 = stats_rand_gen_funiform(0.0, 1.0);
stats_rand_setall(10, 20);
$u2 = stats_rand_gen_funiform(0.0, 1.0);
echo ($u1 === $u2 && $u1 > 0.0 && $u1 < 1.0) ? 'fu_ok' : 'fu_bad';
echo "\n";
echo round($u1, 8), "\n";
stats_rand_setall(10, 20);
$i1 = stats_rand_ibinomial(10, 0.5);
stats_rand_setall(10, 20);
$i2 = stats_rand_ibinomial(10, 0.5);
echo ($i1 === $i2 && $i1 >= 0 && $i1 <= 10) ? 'ibin_ok' : 'ibin_bad';
echo "\n";
echo $i1, "\n";
var_export(@stats_rand_gen_exponential(-1.0));
echo "\n";
?>
--EXPECT--
Y
Y
Y
funcs_ok
605576577 923149679
beta_ok
0.02397895
gamma_ok
chi_ok
4.86125277
f_ok
fu_ok
0.99980736
ibin_ok
10
false
