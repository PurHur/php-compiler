--TEST--
gmp phase-3 powm/fact/gcd/lcm/sqrt/com (ext/gmp/gmp.c; issue #19539)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['gmp_powm','gmp_fact','gmp_gcd','gmp_lcm','gmp_sqrt','gmp_sqrtrem','gmp_perfect_square','gmp_com'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}
echo gmp_strval(gmp_powm(2, 10, 1000)), "\n";
echo gmp_strval(gmp_fact(10)), "\n";
echo gmp_strval(gmp_gcd(48, 18)), "\n";
echo gmp_strval(gmp_lcm(4, 6)), "\n";
echo gmp_strval(gmp_sqrt(100)), "\n";
$sr = gmp_sqrtrem(10);
echo gmp_strval($sr[0]), ' ', gmp_strval($sr[1]), "\n";
echo gmp_perfect_square(16) ? '1' : '0', "\n";
echo gmp_perfect_square(15) ? '1' : '0', "\n";
echo gmp_strval(gmp_com(0)), "\n";
echo gmp_strval(gmp_com(5)), "\n";
?>
--EXPECT--
gmp_powm=yes
gmp_fact=yes
gmp_gcd=yes
gmp_lcm=yes
gmp_sqrt=yes
gmp_sqrtrem=yes
gmp_perfect_square=yes
gmp_com=yes
24
3628800
6
12
10
3 1
1
0
-1
-6
