--TEST--
gmp_init(null) coerces to zero GMP int (ext/gmp/gmp.c; issue #18946)
--FILE--
<?php
$zero = gmp_init(null);
echo gmp_strval($zero), "\n";
echo gmp_cmp($zero, gmp_init(0)), "\n";
?>
--EXPECT--
0
0
