--TEST--
stdlib log10/expm1/log1p (issue #3578; modf/frexp/ldexp phantoms dropped)
--FILE--
<?php
echo log10(100), "\n";
echo expm1(0), "\n";
echo log1p(0), "\n";
--EXPECT--
2
0
0
