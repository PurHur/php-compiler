--TEST--
AOT: acosh()/asinh()/atanh() float return (#27058)
--FILE--
<?php
echo number_format(acosh(2), 5, '.', ''), "\n";
echo number_format(asinh(1), 5, '.', ''), "\n";
echo number_format(atanh(0.5), 5, '.', ''), "\n";
--EXPECT--
1.31696
0.88137
0.54931
--EXPECT_EXIT--
0
