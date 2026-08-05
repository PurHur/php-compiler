--TEST--
AOT: log1p()/expm1() float return (#27057)
--FILE--
<?php
echo round(log1p(1), 5), "\n";
echo round(expm1(1), 5), "\n";
--EXPECT--
0.69315
1.71828
--EXPECT_EXIT--
0
