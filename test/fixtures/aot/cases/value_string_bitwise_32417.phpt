--TEST--
AOT: boxed value ⊙ numeric-string bitwise (#32417, zend_operators.c bitwise_and_function)
--FILE--
<?php
function bits($n, string $s): void
{
    var_dump($n & $s);
    var_dump($s & $n);
    var_dump($n | $s);
    var_dump($n ^ '1');
}
bits(null, '5');
bits(7, '3');
--EXPECT--
int(0)
int(0)
int(5)
int(1)
int(3)
int(3)
int(7)
int(6)
--EXPECT_EXIT--
0
