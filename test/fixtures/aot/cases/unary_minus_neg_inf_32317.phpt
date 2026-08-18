--TEST--
AOT: echo -INF is -INF not 2^63 (#32317, zend_operators.c zendi_negate_function)
--FILE--
<?php
echo -INF, "\n";
echo -(-INF), "\n";
$x = INF;
echo -$x, "\n";
--EXPECT--
-INF
INF
-INF
--EXPECT_EXIT--
0
