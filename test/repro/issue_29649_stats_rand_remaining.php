<?php
/**
 * Issue #29649 — pecl-stats remaining rand_* (chisquare/f/funiform/ibinomial).
 *
 * Run with: PHP_COMPILER_ENABLE_STATS=1 php bin/vm.php test/repro/issue_29649_stats_rand_remaining.php
 */
error_reporting(E_ALL);

echo 'chi=', function_exists('stats_rand_gen_chisquare') ? 'Y' : 'N', "\n";
echo 'f=', function_exists('stats_rand_gen_f') ? 'Y' : 'N', "\n";
echo 'fu=', function_exists('stats_rand_gen_funiform') ? 'Y' : 'N', "\n";
echo 'ibin=', function_exists('stats_rand_ibinomial') ? 'Y' : 'N', "\n";

stats_rand_setall(10, 20);
$c1 = stats_rand_gen_chisquare(2.0);
stats_rand_setall(10, 20);
$c2 = stats_rand_gen_chisquare(2.0);
echo ($c1 === $c2 && $c1 > 0.0) ? 'chi_ok' : 'chi_bad', "\n";
echo 'chi_v=', round($c1, 8), "\n";

stats_rand_setall(10, 20);
$f1 = stats_rand_gen_f(5.0, 10.0);
stats_rand_setall(10, 20);
$f2 = stats_rand_gen_f(5.0, 10.0);
echo ($f1 === $f2 && $f1 > 0.0) ? 'f_ok' : 'f_bad', "\n";

stats_rand_setall(10, 20);
$u1 = stats_rand_gen_funiform(0.0, 1.0);
stats_rand_setall(10, 20);
$u2 = stats_rand_gen_funiform(0.0, 1.0);
echo ($u1 === $u2 && $u1 > 0.0 && $u1 < 1.0) ? 'fu_ok' : 'fu_bad', "\n";
echo 'fu_v=', round($u1, 8), "\n";

stats_rand_setall(10, 20);
$b1 = stats_rand_ibinomial(10, 0.5);
stats_rand_setall(10, 20);
$b2 = stats_rand_ibinomial(10, 0.5);
echo ($b1 === $b2 && $b1 >= 0 && $b1 <= 10) ? 'ibin_ok' : 'ibin_bad', "\n";
echo 'ibin_v=', $b1, "\n";

echo 'bad=', var_export(@stats_rand_gen_chisquare(0.0), true), "\n";
echo 'badn=', var_export(@stats_rand_ibinomial(-1, 0.5), true), "\n";
