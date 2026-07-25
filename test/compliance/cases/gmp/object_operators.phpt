--TEST--
GMP object operators do_operation/compare (ext/gmp/gmp.c; issue #21265)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$a = gmp_init(10);
$b = gmp_init(3);
echo 'add=', gmp_strval($a + $b), "\n";
echo 'sub=', gmp_strval($a - $b), "\n";
echo 'mul=', gmp_strval($a * $b), "\n";
echo 'div=', gmp_strval($a / $b), "\n";
echo 'mod=', gmp_strval($a % $b), "\n";
echo 'and=', gmp_strval($a & $b), "\n";
echo 'or=', gmp_strval($a | $b), "\n";
echo 'xor=', gmp_strval($a ^ $b), "\n";
echo 'shl=', gmp_strval($a << 2), "\n";
echo 'shr=', gmp_strval($a >> 1), "\n";
echo 'pow=', gmp_strval($a ** $b), "\n";
echo 'neg=', gmp_strval(-$a), "\n";
echo 'com=', gmp_strval(~$a), "\n";
echo 'gt=', ($a > $b) ? '1' : '0', "\n";
echo 'sp=', (string) ($a <=> $b), "\n";
echo 'mix=', gmp_strval($a + 5), "\n";
echo 'shrn=', gmp_strval(gmp_init('-7') >> 1), "\n";
?>
--EXPECT--
add=13
sub=7
mul=30
div=3
mod=1
and=2
or=11
xor=9
shl=40
shr=5
pow=1000
neg=-10
com=-11
gt=1
sp=1
mix=15
shrn=-4
