--TEST--
Language: boxed null ⊙ numeric-string arithmetic (#32406, Zend/zend_operators.c convert_scalar_to_number)
--FILE--
<?php
function arith($n, string $s): void
{
    var_dump($n + $s);
    var_dump($s + $n);
    var_dump($n * $s);
    var_dump($n - '1');
}
arith(null, '5');
?>
--EXPECT--
int(5)
int(5)
int(0)
int(-1)
