--TEST--
AOT: tanh() float return (#27126)
--FILE--
<?php
echo round(tanh(1), 6), "\n";
--EXPECT--
0.761594
--EXPECT_EXIT--
0
