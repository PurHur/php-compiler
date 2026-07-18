<?php
/**
 * Repro #20586 — gmp_kronecker / gmp_divexact (php-src-strict).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20586_gmp_kronecker_divexact.php
 */
echo 'gmp_kronecker=', function_exists('gmp_kronecker') ? 'yes' : 'no', "\n";
echo 'gmp_divexact=', function_exists('gmp_divexact') ? 'yes' : 'no', "\n";
$k = gmp_kronecker(2, 5);
$d = gmp_strval(gmp_divexact(10, 5));
echo 'kronecker(2,5)=', $k, "\n";
echo 'divexact(10,5)=', $d, "\n";
echo ($k === -1 && $d === '2') ? "OK\n" : "FAIL\n";
