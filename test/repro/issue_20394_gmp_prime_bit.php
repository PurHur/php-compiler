<?php
$needed = [
    'gmp_prob_prime','gmp_nextprime','gmp_invert','gmp_jacobi','gmp_legendre','gmp_gcdext',
    'gmp_root','gmp_rootrem','gmp_perfect_power','gmp_sign','gmp_testbit','gmp_setbit',
    'gmp_clrbit','gmp_scan0','gmp_scan1','gmp_popcount','gmp_hamdist',
];
$all = true;
foreach ($needed as $f) {
    if (!function_exists($f)) { $all = false; break; }
}
echo 'symbols=', $all ? 'yes' : 'no', "\n";
if (!$all) { exit(0); }
echo 'sign=', gmp_sign(-3), "\n";
echo 'prime=', gmp_prob_prime(gmp_init(17)), "\n";
$x = gmp_init(0);
gmp_setbit($x, 1);
echo 'bit_ok=', (gmp_testbit($x, 1) && gmp_strval($x) === '2') ? '1' : '0', "\n";
$inv = gmp_invert(3, 11);
echo 'invert=', (false !== $inv && gmp_strval($inv) === '4') ? '1' : '0', "\n";
