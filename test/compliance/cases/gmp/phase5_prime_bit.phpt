--TEST--
GMP phase 5: prime / bit / number-theory (#20394)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'sign=', gmp_sign(-3), ',', gmp_sign(0), ',', gmp_sign(9), "\n";
echo 'prime17=', gmp_prob_prime(17), ' next=', gmp_strval(gmp_nextprime(14)), "\n";
$x = gmp_init(1);
gmp_setbit($x, 3);
echo 'set=', gmp_strval($x), ' test3=', gmp_testbit($x, 3) ? '1' : '0', "\n";
gmp_clrbit($x, 3);
echo 'clr=', gmp_strval($x), "\n";
echo 'pop=', gmp_popcount(7), ' ham=', gmp_hamdist(7, 1), "\n";
echo 'scan0=', gmp_scan0(7, 0), ' scan1=', gmp_scan1(8, 0), "\n";
$inv = gmp_invert(3, 11);
echo 'inv=', gmp_strval($inv), "\n";
$ext = gmp_gcdext(240, 46);
echo 'gcd=', gmp_strval($ext['g']), "\n";
echo 'root=', gmp_strval(gmp_root(27, 3)), ' pp=', gmp_perfect_power(16) ? '1' : '0', "\n";
echo 'jac=', gmp_jacobi(5, 11), ' leg=', gmp_legendre(5, 11), "\n";
--EXPECT--
sign=-1,0,1
prime17=2 next=17
set=9 test3=1
clr=1
pop=3 ham=2
scan0=3 scan1=3
inv=4
gcd=2
root=3 pp=1
jac=1 leg=1
