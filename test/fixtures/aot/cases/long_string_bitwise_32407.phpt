--TEST--
AOT: native long ⊙ numeric-string bitwise (#32407, zend_operators.c bitwise_and_function)
--FILE--
<?php
function bits(int $n, bool $t, string $s): void
{
    var_dump($n & $s);
    var_dump($s & $n);
    var_dump($t & $s);
    var_dump($s | 2);
    var_dump($n ^ $s);
}
bits(5, true, '7');
--EXPECT--
int(5)
int(5)
int(1)
int(7)
int(2)
--EXPECT_EXIT--
0
