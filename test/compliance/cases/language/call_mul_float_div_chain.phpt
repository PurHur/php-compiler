--TEST--
Language: int * float / int in call operands — chained Mul/Div wiring (#15929)
--FILE--
<?php
echo sprintf('%.10F', 5 * 200.0 / 12), "\n";
echo number_format(5 * 200.0 / 12, 2), "\n";
$x = 5 * 200.0 / 12;
echo round($x, 2), "\n";
--EXPECT--
83.3333333333
83.33
83.33
