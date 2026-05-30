--TEST--
stdlib log10/expm1/log1p/modf/ldexp/frexp (issue #3578)
--FILE--
<?php
echo log10(100), "\n";
echo expm1(0), "\n";
echo log1p(0), "\n";
$frac = 0.0;
echo modf(3.14, $frac), " ", (int) $frac, "\n";
echo ldexp(0.5, 1), "\n";
$exp = 0;
echo frexp(8, $exp), " ", $exp, "\n";
--EXPECT--
2
0
0
0.14 3
1
0.5 4
