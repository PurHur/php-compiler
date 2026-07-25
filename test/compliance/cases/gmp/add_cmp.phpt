--TEST--
gmp add/cmp phase-1 parity (ext/gmp/gmp.c; issue #3341)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$a = gmp_init('999999999999999999999');
$b = gmp_init('1');
echo gmp_strval(gmp_add($a, $b)), "\n";
echo gmp_cmp($a, $b), "\n";
echo gmp_strval(gmp_init('ff', 16)), "\n";
?>
--EXPECT--
1000000000000000000000
1
255
