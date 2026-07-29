--TEST--
AOT: log10/expm1/log1p/modf/frexp (issue #3578; ldexp dropped #24607)
--FILE--
<?php
echo log10(100), "\n";
echo expm1(0), "\n";
echo log1p(0), "\n";
$frac = 0.0;
echo modf(3.14, $frac), " ", (int) $frac, "\n";
$exp = 0;
echo frexp(8, $exp), " ", $exp, "\n";
--EXPECT--
2
0
0
0.14 3
0.5 4
--EXPECT_EXIT--
0
