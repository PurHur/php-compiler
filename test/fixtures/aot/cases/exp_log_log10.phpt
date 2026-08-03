--TEST--
AOT: exp()/log()/log10() float return (#27047)
--FILE--
<?php
echo intval(exp(1) * 1000000), "\n";
echo intval(log(10) * 1000000), "\n";
echo log10(1000), "\n";
--EXPECT--
2718281
2302585
3
--EXPECT_EXIT--
0
