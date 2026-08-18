--TEST--
AOT: native float bitwise via convert_to_long (#32414, zend_operators.c bitwise_and_function)
--FILE--
<?php
function bits(float $a, int $b, float $c, string $s): void
{
    var_dump($a & $b);
    var_dump($b & $a);
    var_dump($a | $b);
    var_dump($a ^ $b);
    var_dump($a & $c);
    var_dump($a & $s);
    var_dump($s & $a);
}
bits(5.0, 3, 3.0, '7');
--EXPECT--
int(1)
int(1)
int(7)
int(6)
int(1)
int(5)
int(5)
--EXPECT_EXIT--
0
