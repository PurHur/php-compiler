--TEST--
AOT: native bool ⊙ numeric-string arithmetic (#32401, zend_operators.c convert_scalar_to_number)
--FILE--
<?php
function arith(bool $t, bool $f, string $s): void
{
    var_dump($t + $s);
    var_dump($s + $t);
    var_dump($t * $s);
    var_dump($t - '1');
    var_dump($t / '2');
    var_dump($f * $s);
    var_dump($t <=> '0');
    echo ($t > '0') ? "gt\n" : "ngt\n";
}
arith(true, false, '5');
--EXPECT--
int(6)
int(6)
int(5)
int(0)
float(0.5)
int(0)
int(1)
gt
--EXPECT_EXIT--
0
