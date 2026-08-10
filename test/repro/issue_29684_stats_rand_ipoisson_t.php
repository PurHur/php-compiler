<?php
/**
 * Issue #29684 — pecl-stats ipoisson / ibinomial_negative / gen_t.
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_29684_stats_rand_ipoisson_t.php
 */
error_reporting(E_ALL);

echo 'poi=', function_exists('stats_rand_gen_ipoisson') ? 'Y' : 'N', "\n";
echo 'neg=', function_exists('stats_rand_ibinomial_negative') ? 'Y' : 'N', "\n";
echo 't=', function_exists('stats_rand_gen_t') ? 'Y' : 'N', "\n";

stats_rand_setall(10, 20);
$p1 = stats_rand_gen_ipoisson(5.0);
stats_rand_setall(10, 20);
$p2 = stats_rand_gen_ipoisson(5.0);
echo ($p1 === $p2 && $p1 >= 0) ? 'poi_ok' : 'poi_bad', "\n";
echo 'poi_v=', $p1, "\n";

stats_rand_setall(10, 20);
$pBig1 = stats_rand_gen_ipoisson(20.0);
stats_rand_setall(10, 20);
$pBig2 = stats_rand_gen_ipoisson(20.0);
echo ($pBig1 === $pBig2 && $pBig1 >= 0) ? 'poi_big_ok' : 'poi_big_bad', "\n";
echo 'poi_big_v=', $pBig1, "\n";

stats_rand_setall(10, 20);
$n1 = stats_rand_ibinomial_negative(5, 0.5);
stats_rand_setall(10, 20);
$n2 = stats_rand_ibinomial_negative(5, 0.5);
echo ($n1 === $n2 && $n1 >= 0) ? 'neg_ok' : 'neg_bad', "\n";
echo 'neg_v=', $n1, "\n";

stats_rand_setall(10, 20);
$t1 = stats_rand_gen_t(10.0);
stats_rand_setall(10, 20);
$t2 = stats_rand_gen_t(10.0);
echo ($t1 === $t2) ? 't_ok' : 't_bad', "\n";
echo 't_v=', round($t1, 8), "\n";

echo 'bad_poi=', var_export(@stats_rand_gen_ipoisson(-1.0), true), "\n";
echo 'bad_neg=', var_export(@stats_rand_ibinomial_negative(0, 0.5), true), "\n";
echo 'bad_t=', var_export(@stats_rand_gen_t(0.0), true), "\n";
