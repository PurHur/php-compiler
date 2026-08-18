--TEST--
Language: echo -INF is -INF (zendi_negate_function IS_DOUBLE, #32317)
--FILE--
<?php
echo -INF, "\n";
echo -(-INF), "\n";
$x = INF;
echo -$x, "\n";
?>
--EXPECT--
-INF
INF
-INF
